<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Department;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\User;
use App\Support\Access;
use App\Support\ActionCenter;
use App\Support\Pages;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-243: page-level access the administrator edits per role and per person; one resolver for every check. */
class PageAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $org = Organization::create(['name' => 'Page Farm', 'slug' => 'page-farm']);
        Tenant::set($org->id);
        Department::seedDefaults((string) $org->id);
    }

    private function user(string $role, string $name = ''): User
    {
        $name = $name ?: $role;

        return User::firstOrCreate(['email' => "$name@page.test"], ['name' => "Test $name", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current(), 'active' => true, 'department_id' => Department::forRole($role)]);
    }

    /** Post the role screen with the current apps and actions plus these page ticks. */
    private function saveRoles(array $pages)
    {
        $org = Tenant::current();

        return $this->actingAs($this->user('ceo'))->post('/settings/access', ['modules' => Access::roleModules($org), 'gates' => Access::roleGates($org), 'pages' => $pages]);
    }

    private function fresh(User $u): User
    {
        Tenant::flush();

        return $u->fresh();
    }

    public function test_every_menu_page_is_in_the_registry_with_a_unique_key(): void
    {
        $keys = collect(config('kkfarm_pages'))->pluck('key');
        $this->assertSame($keys->count(), $keys->unique()->count());
        $this->assertGreaterThan(150, $keys->count());
        foreach (['health.medical_cases', 'health.triage_reported_symptoms', 'breeding.breeding_eligibility_approval', 'welfare.humane_end_of_life_review'] as $k) {
            $this->assertSame('vet', Pages::registry()[$k]['lock'], $k);
        }
        $this->assertSame('admin', Pages::rule('/dashboard/scorecard')['lock']);
        $this->assertSame('admin', Pages::rule('/settings/users')['lock']);
    }

    public function test_resolver_default_role_override_person_override_and_locks(): void
    {
        $fin = $this->user('finance');
        $this->assertTrue(Pages::allows($fin, '/finance/books', ['tab' => 'pl']), 'default');

        // role override closes it
        $this->saveRoles(['finance.profit_loss' => ['finance' => false]])->assertSessionHasNoErrors();
        $fin = $this->fresh($fin);
        $this->assertFalse(Pages::allows($fin, '/finance/books', ['tab' => 'pl']));
        $this->assertTrue(Pages::allows($fin, '/finance/books', ['tab' => 'journal']), 'other tabs untouched');
        $this->assertSame(0, DB::table('role_page_access')->where('allowed', true)->count(), 'only differences are stored');

        // the person's own tick wins over the role
        $this->actingAs($this->user('ceo'))->post("/settings/users/{$fin->id}/access", ['pages' => ['finance.profit_loss' => true]])->assertSessionHasNoErrors();
        $this->assertTrue(Pages::allows($this->fresh($fin), '/finance/books', ['tab' => 'pl']));
        $this->actingAs($this->user('ceo'))->post("/settings/users/{$fin->id}/access", ['pages' => ['finance.profit_loss' => null]])->assertSessionHasNoErrors();
        $this->assertFalse(Pages::allows($this->fresh($fin), '/finance/books', ['tab' => 'pl']), 'back to the role');

        // locks: the role screen cannot change Administrator-only or vet-locked pages
        $this->saveRoles(['dashboard.ceo_scorecard' => ['manager' => true, 'ceo' => false], 'health.medical_cases' => ['caretaker' => true, 'vet' => false]])->assertSessionHasNoErrors();
        $this->assertFalse(Pages::allows($this->fresh($this->user('manager')), '/dashboard/scorecard'));
        $this->assertTrue(Pages::allows($this->fresh($this->user('ceo')), '/dashboard/scorecard'));
        $this->assertFalse(Pages::allows($this->fresh($this->user('caretaker')), '/health', ['tab' => 'cases']));
        $this->assertTrue(Pages::allows($this->fresh($this->user('vet')), '/health', ['tab' => 'cases']));

        // a vet-locked page may be given to one person
        $care = $this->user('caretaker');
        $this->actingAs($this->user('ceo'))->post("/settings/users/{$care->id}/access", ['pages' => ['health.medical_cases' => true, 'dashboard.ceo_scorecard' => true]])->assertSessionHasNoErrors();
        $care = $this->fresh($care);
        $this->assertTrue(Pages::allows($care, '/health', ['tab' => 'cases']));
        $this->assertFalse(Pages::allows($care, '/dashboard/scorecard'), 'the Scorecard is never given to anyone else');
    }

    public function test_the_last_administrator_keeps_settings_and_the_scorecard(): void
    {
        $owner = $this->user('ceo');
        $this->actingAs($owner)->post("/settings/users/{$owner->id}/access", ['pages' => ['settings.settings_every_settings_page' => false, 'dashboard.ceo_scorecard' => false]])->assertSessionHasNoErrors();
        $owner = $this->fresh($owner);
        foreach (['/settings', '/settings/users', '/settings/access', '/dashboard/scorecard'] as $url) {
            $this->actingAs($owner)->get($url)->assertOk();
        }
        $this->assertSame(0, DB::table('user_page_access')->count());
    }

    public function test_unticking_a_page_for_finance_closes_it_everywhere(): void
    {
        $fin = $this->user('finance');
        LedgerEntry::create(['direction' => 'out', 'category' => 'Feed', 'amount' => 500, 'occurred_on' => now()->toDateString(), 'payment_status' => 'pending', 'recorded_by' => 'x']);
        $keys = fn () => collect(ActionCenter::items($this->fresh($fin)))->pluck('key')->all();
        $this->assertContains('unpaid', $keys());
        $this->actingAs($fin)->get('/finance/books?tab=pl')->assertOk();

        $this->saveRoles(['finance.profit_loss' => ['finance' => false], 'finance.income_farm_expenses' => ['finance' => false]])->assertSessionHasNoErrors();
        $fin = $this->fresh($fin);
        // menu: the shared rules say closed
        $this->actingAs($fin)->get('/home')->assertInertia(fn (AssertableInertia $p) => $p->where('auth.pages', fn ($rules) => collect($rules)->contains(fn ($r) => $r[0] === '/finance/books' && ($r[1]['tab'] ?? null) === 'pl' && $r[2] === false)));
        // address: 403
        $this->actingAs($fin)->get('/finance/books?tab=pl')->assertForbidden();
        $this->actingAs($fin)->get('/finance')->assertForbidden();
        // data: the other tabs still open, the closed tab's rows are not in the page
        $this->actingAs($fin)->get('/finance/books?tab=journal')->assertInertia(fn (AssertableInertia $p) => $p->where('pl.income', [])->where('pl.expenses', []));
        // notifications that link to the closed page are not sent
        $this->assertNotContains('unpaid', $keys());
        $this->assertContains('unpaid', collect(ActionCenter::items($this->fresh($this->user('ceo'))))->pluck('key')->all());
        // every change is in the activity log for the owner
        $this->assertSame(2, Activity::where('audience', 'security')->where('action', 'like', 'Page access: Finance Employee%')->count());
    }

    public function test_ticking_a_page_for_cleaning_opens_it_only_with_its_app(): void
    {
        $cln = $this->user('cleaning');
        $this->actingAs($cln)->get('/environment?tab=setup')->assertForbidden();
        $this->saveRoles(['environment.water_points_heat_limit' => ['cleaning' => true], 'goats.all_goats' => ['cleaning' => true]])->assertSessionHasNoErrors();
        $cln = $this->fresh($cln);
        $this->actingAs($cln)->get('/environment?tab=setup')->assertOk();
        $this->actingAs($cln)->get('/goats')->assertForbidden(); // Cleaning does not have the Livestock app
    }

    public function test_reset_to_defaults_restores_the_d241_behaviour(): void
    {
        $vet = $this->user('vet');
        $this->saveRoles(['feeding.feed_pens' => ['vet' => false]])->assertSessionHasNoErrors();
        $this->actingAs($this->fresh($vet))->get('/feeding?tab=now')->assertForbidden();
        $default = Pages::registry()['feeding.feed_pens'];
        $this->saveRoles(['feeding.feed_pens' => collect(array_keys(config('kkfarm.roles')))->mapWithKeys(fn ($r) => [$r => Pages::defaultFor($r, $default)])->all()])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('role_page_access')->count());
        $this->actingAs($this->fresh($vet))->get('/feeding?tab=now')->assertOk();
    }

    public function test_the_access_screen_lists_pages_by_app_with_defaults_and_custom(): void
    {
        $this->saveRoles(['feeding.feed_pens' => ['vet' => false]]);
        Tenant::flush();
        $this->actingAs($this->user('ceo'))->get('/settings/access')->assertInertia(fn (AssertableInertia $p) => $p->component('Settings/Access')
            ->where('pages', fn ($rows) => ($r = collect($rows)->firstWhere('key', 'feeding.feed_pens')) && $r['value']['vet'] === false && $r['default']['vet'] === true && $r['group'] !== ''
                && collect($rows)->firstWhere('key', 'dashboard.ceo_scorecard')['lock'] === 'admin'
                && ! collect($rows)->contains(fn ($x) => in_array($x['lock'], ['opening', 'always'], true))));
        $this->actingAs($this->user('manager'))->post('/settings/access', ['modules' => [], 'gates' => [], 'pages' => []])->assertForbidden();
    }

    public function test_a_page_missing_from_the_registry_is_for_the_administrator_only(): void
    {
        $this->assertFalse(Pages::allows($this->user('manager'), '/something-new'));
        $this->assertTrue(Pages::allows($this->user('ceo'), '/something-new'));
        $this->assertTrue(Pages::allows($this->user('cleaning'), '/account/password'));
    }
}
