<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Animal;
use App\Models\Breed;
use App\Models\Department;
use App\Models\HealthCase;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Support\ActionCenter;
use App\Support\Pages;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-241: the acceptance tests of KK_FARMS_RBAC.md §13, one user per role. */
class RbacTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['ceo', 'manager', 'vet', 'caretaker', 'feeding', 'cleaning', 'finance'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $org = Organization::create(['name' => 'RBAC Farm', 'slug' => 'rbac-farm']);
        Tenant::set($org->id);
        Department::seedDefaults((string) $org->id);
    }

    private function user(string $role, string $name = ''): User
    {
        $name = $name ?: $role;

        return User::firstOrCreate(['email' => "$name@rbac.test"], ['name' => "Test $name", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current(), 'active' => true,
            'department_id' => Department::forRole($role)]);
    }

    private function modules(User $u): array
    {
        Tenant::flush();

        return array_values(array_filter(array_keys(config('kkfarm.modules')), fn ($m) => $u->fresh()->canModule($m)));
    }

    /** §13.1 */
    public function test_finance_sees_only_its_apps_and_no_goat_data(): void
    {
        $fin = $this->user('finance');
        $this->assertEqualsCanonicalizing(['dashboard', 'tasks', 'employees', 'finance', 'value', 'milking', 'reports', 'inventory', 'purchase', 'sales', 'animal_purchase', 'feed_cost'], $this->modules($fin));
        foreach (['/goats', '/health', '/feeding', '/fodder', '/housing', '/breeding', '/scan'] as $url) {
            $this->actingAs($fin)->get($url)->assertForbidden();
        }
        foreach (['/finance', '/sales', '/purchase', '/value', '/home'] as $url) {
            $this->actingAs($fin)->get($url)->assertOk();
        }
        $this->actingAs($fin)->get('/home')->assertInertia(fn (AssertableInertia $p) => $p->where('auth.modules', fn ($m) => ! collect($m)->contains('goats'))); // so no "Search goats"
    }

    /** §13.2 */
    public function test_only_admin_and_manager_open_the_executive_dashboard_and_only_admin_the_scorecard(): void
    {
        foreach (self::ROLES as $role) {
            $u = $this->user($role);
            in_array($role, ['ceo', 'manager'], true)
                ? $this->actingAs($u)->get('/')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Dashboard'))
                : $this->actingAs($u)->get('/')->assertRedirect('/home');
            $role === 'ceo'
                ? $this->actingAs($u)->get('/dashboard/scorecard')->assertOk()
                : $this->actingAs($u)->get('/dashboard/scorecard')->assertForbidden();
            $this->assertSame(in_array($role, ['ceo', 'manager'], true) ? '/' : '/home', Pages::home($u));
        }
        $this->actingAs($this->user('vet'))->get('/home')->assertInertia(fn (AssertableInertia $p) => $p->component('Home')->where('links', fn ($l) => collect($l)->pluck('href')->contains('/health?tab=cases')));
    }

    /** §13.3 */
    public function test_there_is_no_linked_apps_section(): void
    {
        $this->assertStringNotContainsString('>Linked Apps<', file_get_contents(resource_path('js/components/AppShell.tsx')));
        $this->assertStringNotContainsString('app.linked', file_get_contents(resource_path('js/components/AppShell.tsx')));
    }

    /** §13.4 */
    public function test_cleaning_sees_cleaning_work_only(): void
    {
        $c = $this->user('cleaning');
        $this->assertEqualsCanonicalizing(['dashboard', 'sheds', 'tasks', 'inventory', 'environment', 'maintenance'], $this->modules($c));
        foreach (['/goats', '/health', '/finance', '/feeding', '/sheds', '/activity'] as $url) {
            $this->actingAs($c)->get($url)->assertForbidden();
        }
        $this->actingAs($c)->get('/housing')->assertOk();
        $this->actingAs($c)->get('/inventory')->assertInertia(fn (AssertableInertia $p) => $p->where('stores', ['store']));
    }

    /** §13.5 */
    public function test_feeding_runs_feeding_but_cannot_approve_plans_or_see_health_and_money(): void
    {
        $f = $this->user('feeding');
        Organization::whereKey(Tenant::current())->update(['fodder_enabled' => true]); // Fodder is optional (D-202)
        Tenant::flush();
        foreach (['/feeding', '/fodder', '/feed-cost?tab=stock'] as $url) {
            $r = $this->actingAs($f)->get($url);
            $this->assertSame(200, $r->status(), $url);
        }
        foreach (['/health', '/finance', '/feed-cost', '/goats/register'] as $url) {
            $this->actingAs($f)->get($url)->assertForbidden();
        }
        $this->assertTrue($f->passes('plan_rations'));
        $this->assertFalse($f->passes('approve_feeding_plan'));
        $this->assertFalse($f->passes('record_weights'));
        $this->actingAs($f)->get('/inventory')->assertInertia(fn (AssertableInertia $p) => $p->where('stores', ['feed']));
    }

    /** §13.6 */
    public function test_caretaker_records_work_but_never_treats_or_sees_money(): void
    {
        $c = $this->user('caretaker');
        foreach (['record_weights', 'record_milk', 'health_checkup', 'assign_tags'] as $g) {
            $this->assertTrue($c->passes($g), $g);
        }
        foreach (['clinical', 'open_cases', 'approve_breeding', 'edit_goats'] as $g) {
            $this->assertFalse($c->passes($g), $g);
        }
        foreach (['/finance', '/sales', '/value', '/health?tab=cases'] as $url) {
            $this->actingAs($c)->get($url)->assertForbidden();
        }
        $this->actingAs($c)->get('/health?tab=reports')->assertOk(); // report a sick goat
        $goat = Animal::create(['farm_number' => 'C-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Beetal'])->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'status' => 'active']);
        HealthCase::create(['case_no' => 1, 'animal_id' => $goat->id, 'onset_on' => now(), 'condition' => 'Pneumonia', 'severity' => 'low', 'recorded_by' => 'x']);
        $this->actingAs($c)->get('/health')->assertInertia(fn (AssertableInertia $p) => $p->has('cases', 0)->where('mortality', null)); // §7: no diagnoses in the page data
        $this->actingAs($c)->get("/goats/{$goat->id}")->assertInertia(fn (AssertableInertia $p) => $p->has('cases', 0));
        $this->actingAs($this->user('vet'))->get('/health')->assertInertia(fn (AssertableInertia $p) => $p->has('cases', 1));
    }

    /** §13.7 */
    public function test_only_the_vet_diagnoses_treats_opens_cases_and_approves_breeding(): void
    {
        foreach (self::ROLES as $role) {
            foreach (['clinical', 'open_cases', 'approve_breeding'] as $g) {
                $this->assertSame($role === 'vet', $this->user($role)->passes($g), "$role $g");
            }
        }
        foreach (['/finance', '/finance/books', '/sales'] as $url) {
            $this->actingAs($this->user('vet'))->get($url)->assertForbidden();
        }
    }

    /** §13.8 */
    public function test_manager_runs_the_farm_without_settings_scorecard_or_device_keys(): void
    {
        $m = $this->user('manager');
        foreach (['/settings', '/settings/users', '/settings/access', '/dashboard/scorecard', '/sensors?tab=connect', '/finance/books?tab=journal'] as $url) {
            $this->actingAs($m)->get($url)->assertForbidden();
        }
        $this->assertFalse($m->passes('manage_devices'));
        $this->assertTrue($m->passes('approve_feeding_plan'));
        $this->actingAs($m)->get('/goats')->assertOk();
    }

    /** §13.9 and §11: the administrator keeps Settings; the last Administrator cannot be removed. */
    public function test_admin_keeps_settings_and_the_last_admin_stays(): void
    {
        $owner = $this->user('ceo');
        $this->actingAs($owner)->get('/settings')->assertOk();
        $this->actingAs($owner)->post("/settings/users/{$owner->id}", ['role' => 'manager'])->assertSessionHasErrors('role');

        $second = $this->user('ceo', 'second');
        $this->actingAs($owner)->post("/settings/users/{$second->id}", ['role' => 'manager'])->assertSessionHasNoErrors();
        $this->assertSame('manager', $second->fresh()->role);
        // a manager given Settings personally still cannot demote the last Administrator
        $second->forceFill(['module_access' => ['settings' => true]])->save();
        Tenant::flush();
        $this->actingAs($second->fresh())->post("/settings/users/{$owner->id}", ['role' => 'manager']);
        $this->assertSame('ceo', $owner->fresh()->role);
    }

    /** §13.10 */
    public function test_a_refused_request_is_403_and_audited(): void
    {
        $c = $this->user('caretaker');
        $this->actingAs($c)->post('/finance/entries', ['direction' => 'out', 'category' => 'Feed', 'amount' => 1, 'occurred_on' => now()->toDateString()])->assertForbidden();
        $this->actingAs($c)->get('/dashboard/scorecard')->assertForbidden();
        $this->assertSame(2, Activity::where('audience', 'security')->where('actor', 'Test caretaker')->count());
        $this->actingAs($this->user('manager'))->get('/activity')->assertInertia(fn (AssertableInertia $p) => $p->where('activity', fn ($a) => collect($a)->every(fn ($r) => ! str_starts_with($r['action'], 'Access refused'))));
    }

    /** §13.11 and §9: notifications and tasks reach only their roles and departments. */
    public function test_notifications_and_tasks_reach_only_their_people(): void
    {
        $goat = Animal::create(['farm_number' => 'N-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Beetal'])->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'status' => 'active']);
        HealthCase::create(['case_no' => 1, 'animal_id' => $goat->id, 'onset_on' => now(), 'condition' => 'X', 'severity' => 'low', 'recorded_by' => 'x']);
        $keys = fn (string $role) => collect(ActionCenter::items($this->user($role)))->pluck('key')->all();
        foreach (['ceo', 'manager', 'vet', 'caretaker'] as $role) {
            $this->assertContains('open_cases', $keys($role), $role);
        }
        foreach (['feeding', 'cleaning', 'finance'] as $role) {
            $this->assertNotContains('open_cases', $keys($role), $role);
        }

        Task::create(['title' => 'Clean pen 3', 'type' => 'other', 'department_id' => Department::forRole('cleaning'), 'due_on' => now()->subDay(), 'priority' => 'medium', 'status' => 'open']);
        Task::create(['title' => 'Weigh kids', 'type' => 'other', 'department_id' => Department::forRole('caretaker'), 'due_on' => now()->subDay(), 'priority' => 'medium', 'status' => 'open']);
        $tasks = fn (string $role) => Task::visibleTo($this->user($role))->pluck('title')->all();
        $this->assertSame(['Clean pen 3'], $tasks('cleaning'));
        $this->assertSame(['Weigh kids'], $tasks('caretaker'));
        $this->assertSame([], $tasks('finance'));
        $this->assertCount(2, $tasks('manager'));
        $this->assertNotContains('tasks_overdue', $keys('finance'));
        $this->actingAs($this->user('finance'))->post('/tasks/'.Task::where('title', 'Clean pen 3')->value('id').'/toggle')->assertForbidden();

        // a caretaker's own task is theirs, whatever department they pick
        $this->actingAs($this->user('caretaker'))->post('/tasks', ['title' => 'My own note', 'type' => 'other', 'priority' => 'low', 'department_id' => Department::forRole('finance')])->assertSessionHasNoErrors();
        $this->assertSame(Department::forRole('caretaker'), Task::where('title', 'My own note')->value('department_id'));
    }

    /** §13.12 */
    public function test_a_personal_grant_opens_an_app_and_shows_as_custom(): void
    {
        $owner = $this->user('ceo');
        $fin = $this->user('finance');
        $this->actingAs($fin)->get('/goats')->assertForbidden();
        $this->actingAs($owner)->post("/settings/users/{$fin->id}/access", ['modules' => ['goats' => true]])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->actingAs($fin->fresh())->get('/goats')->assertOk();
        $this->actingAs($owner)->get("/settings/users/{$fin->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('modules', fn ($m) => collect($m)->firstWhere('key', 'goats')['own'] === true));
        $this->actingAs($owner)->post("/settings/users/{$fin->id}/access", ['modules' => ['goats' => null]])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->actingAs($fin->fresh())->get('/goats')->assertForbidden();

        $this->actingAs($owner)->get('/settings/users')->assertInertia(fn (AssertableInertia $p) => $p->where('users', fn ($u) => in_array('Livestock', collect($u)->firstWhere('role', 'finance')['diffs']['caretaker']['gain'], true)
            || collect(collect($u)->firstWhere('role', 'finance')['diffs']['caretaker']['gain'])->isNotEmpty()));
    }
}
