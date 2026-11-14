<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Support\Access;
use App\Support\AccessTrace;
use App\Support\Pages;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-266: the change history with rollback, the "Why?" explainer and the health checks of Settings → Access Rights. */
class AccessToolsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $ceo;
    private User $manager;
    private User $feeding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'Tools Farm', 'slug' => 'tools-farm']);
        Tenant::set($this->org->id);
        Department::seedDefaults((string) $this->org->id);
        $make = fn (string $role) => User::create(['name' => "Test $role", 'email' => "$role@tools.test", 'password' => 'x', 'role' => $role, 'org_id' => $this->org->id, 'active' => true, 'department_id' => Department::forRole($role)]);
        [$this->ceo, $this->manager, $this->feeding] = [$make('ceo'), $make('manager'), $make('feeding')];
    }

    /** The whole role screen payload as it stands, with one app changed for one role. */
    private function roleScreen(string $module, string $role, bool $on): array
    {
        $mods = Access::roleModules($this->org->id);
        $mods[$module] = $on ? array_values(array_unique([...$mods[$module], $role])) : array_values(array_diff($mods[$module], [$role]));

        return ['modules' => $mods, 'gates' => Access::roleGates($this->org->id)];
    }

    public function test_a_role_change_is_recorded_and_can_be_undone(): void
    {
        $this->assertTrue(in_array('feeding', Access::roleModules($this->org->id)['inventory'], true));
        $this->actingAs($this->ceo)->post('/settings/access', $this->roleScreen('inventory', 'feeding', false))->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertFalse(in_array('feeding', Access::roleModules($this->org->id)['inventory'], true));
        $rows = DB::table('access_changes')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['role_module', 'feeding', 'inventory', '1', '0'], [$rows[0]->kind, $rows[0]->subject, $rows[0]->key, $rows[0]->old_value, $rows[0]->new_value]);
        $this->assertSame('Test ceo', $rows[0]->actor);

        $this->actingAs($this->ceo)->post("/settings/access/history/{$rows[0]->batch}/rollback")->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertTrue(in_array('feeding', Access::roleModules($this->org->id)['inventory'], true), 'the app is back for the role');
        $this->assertSame(2, DB::table('access_changes')->count(), 'the undo is itself in the history; nothing was deleted');
        $this->assertSame('Rollback of '.$rows[0]->batch, DB::table('access_changes')->orderByDesc('id')->value('note'));
    }

    public function test_a_page_tick_and_a_persons_own_ticks_are_recorded_and_undone(): void
    {
        $key = 'feeding.feeding_history';
        $roles = $this->roleScreen('inventory', 'ceo', true);
        $this->actingAs($this->ceo)->post('/settings/access', $roles + ['pages' => [$key => ['vet' => false]]])->assertSessionHasNoErrors();
        $vet = User::create(['name' => 'Vet', 'email' => 'vet@tools.test', 'password' => 'x', 'role' => 'vet', 'org_id' => $this->org->id, 'active' => true]);
        Tenant::flush();
        $this->assertFalse(Pages::allowsKey($vet->fresh(), $key));
        $batch = DB::table('access_changes')->where('kind', 'role_page')->value('batch');
        $this->actingAs($this->ceo)->post("/settings/access/history/$batch/rollback");
        Tenant::flush();
        $this->assertTrue(Pages::allowsKey($vet->fresh(), $key), 'the page is open to the vet again');

        // a person's own ticks
        $this->actingAs($this->ceo)->post("/settings/users/{$this->feeding->id}/access", ['modules' => ['finance' => true], 'gates' => ['manage_books' => true]])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertTrue($this->feeding->fresh()->canModule('finance'));
        $this->assertTrue($this->feeding->fresh()->passes('manage_books'));
        $kinds = DB::table('access_changes')->where('subject', (string) $this->feeding->id)->pluck('kind')->sort()->values()->all();
        $this->assertSame(['user_gate', 'user_module'], $kinds);
        $batch = DB::table('access_changes')->where('subject', (string) $this->feeding->id)->value('batch');
        $this->actingAs($this->ceo)->post("/settings/access/history/$batch/rollback");
        Tenant::flush();
        $this->assertFalse($this->feeding->fresh()->canModule('finance'));
        $this->assertFalse($this->feeding->fresh()->passes('manage_books'));
        $this->assertNull($this->feeding->fresh()->module_access);
    }

    public function test_only_the_administrator_uses_the_tools(): void
    {
        $this->actingAs($this->manager)->get('/settings/access/tools')->assertForbidden();
        $this->actingAs($this->manager)->post('/settings/access/history/'.\Illuminate\Support\Str::uuid().'/rollback')->assertForbidden();
        $this->actingAs($this->ceo)->get('/settings/access/tools')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Settings/AccessTools')->where('tab', 'history'));
        $this->actingAs($this->ceo)->post('/settings/access/history/'.\Illuminate\Support\Str::uuid().'/rollback')->assertNotFound();
    }

    public function test_why_explains_with_the_answer_of_the_real_resolver(): void
    {
        foreach ([['module', 'finance'], ['gate', 'manage_books'], ['gate', 'clinical'], ['page', 'feeding.feeding_history'], ['page', 'dashboard.ceo_scorecard'], ['module', 'settings']] as [$kind, $key]) {
            foreach ([$this->manager, $this->feeding, $this->ceo] as $who) {
                $t = AccessTrace::explain($who->fresh(), $kind, $key);
                $real = match ($kind) {
                    'module' => $who->fresh()->canModule($key),
                    'gate' => $who->fresh()->passes($key),
                    default => Pages::allowsKey($who->fresh(), $key),
                };
                $this->assertSame($real, $t['decision'], "$kind $key for {$who->role}");
                $this->assertNotEmpty($t['steps']);
            }
        }
        $this->actingAs($this->ceo)->get("/settings/access/tools?tab=why&user={$this->manager->id}&item=".urlencode('gate|manage_books'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('why.trace.decision', false)->where('why.trace.person', 'Test manager')->has('why.trace.steps'));
    }

    public function test_the_health_checks_render_and_flag_a_single_administrator(): void
    {
        $this->actingAs($this->ceo)->get('/settings/access/tools?tab=health')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('health', fn ($h) => collect($h)->firstWhere('label', 'Active Administrators')['status'] === 'warn'
                && collect($h)->firstWhere('label', 'Write routes with no rule')['status'] === 'ok'
                && collect($h)->firstWhere('label', 'Accounts that never signed in')['status'] === 'warn'));
    }
}
