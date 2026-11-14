<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Support\Access;
use App\Support\Levels;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-266 step 5c: levels (None, View, Contribute, Edit, Manage) per role and app, as a layer over the existing ticks. */
class LevelsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $ceo;
    private User $manager;
    private User $care;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'Levels Farm', 'slug' => 'levels-farm']);
        Tenant::set($this->org->id);
        Department::seedDefaults((string) $this->org->id);
        $make = fn (string $role) => User::create(['name' => "Test $role", 'email' => "$role@levels.test", 'password' => 'x', 'role' => $role, 'org_id' => $this->org->id, 'active' => true, 'department_id' => Department::forRole($role)]);
        [$this->ceo, $this->manager, $this->care] = [$make('ceo'), $make('manager'), $make('caretaker')];
    }

    private function snapshot(): array
    {
        Tenant::flush();
        $sorted = fn (array $m) => array_map(function ($roles) { sort($roles); return $roles; }, $m); // who holds it, not the order they were listed in

        return [$sorted(Access::roleModules($this->org->id)), $sorted(Access::roleGates($this->org->id))];
    }

    public function test_the_levels_are_read_from_what_a_role_holds_today(): void
    {
        $this->assertSame('edit', Levels::current($this->org->id, 'feeding', 'feeding')['level']);
        $this->assertSame('none', Levels::current($this->org->id, 'finance', 'goats')['level']);
        $this->assertSame('manage', Levels::current($this->org->id, 'finance', 'finance')['level']);
        $c = Levels::current($this->org->id, 'caretaker', 'milking');
        $this->assertSame('custom', $c['level']);
        $this->assertNotEmpty($c['differs'], 'a custom cell says which actions differ');
        foreach (Levels::areas() as $area => $_) { // every cell resolves to a known level
            $this->assertArrayHasKey(Levels::current($this->org->id, 'vet', $area)['level'], Levels::LABELS);
        }
    }

    public function test_picking_a_level_ticks_exactly_its_actions_and_the_server_follows(): void
    {
        $url = '/dairy/sessions'; // needs the action record_milk
        $this->assertNotSame(403, $this->actingAs($this->care)->post($url)->getStatusCode());
        $this->actingAs($this->ceo)->post('/settings/access/levels', ['role' => 'caretaker', 'area' => 'milking', 'level' => 'view'])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertSame('view', Levels::current($this->org->id, 'caretaker', 'milking')['level']);
        $this->actingAs($this->care->fresh())->post($url)->assertForbidden(); // the action is gone
        $this->actingAs($this->ceo)->post('/settings/access/levels', ['role' => 'caretaker', 'area' => 'milking', 'level' => 'contribute'])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertSame('contribute', Levels::current($this->org->id, 'caretaker', 'milking')['level']);
        $this->assertNotSame(403, $this->actingAs($this->care->fresh())->post($url)->getStatusCode());
        $this->assertFalse($this->care->fresh()->passes('manage_milking'), 'Manage-level actions are not held at Contribute');
        $this->actingAs($this->ceo)->post('/settings/access/levels', ['role' => 'caretaker', 'area' => 'milking', 'level' => 'none']);
        Tenant::flush();
        $this->assertFalse($this->care->fresh()->canModule('milking'));
    }

    public function test_a_level_pick_is_in_the_history_and_an_undo_puts_everything_back(): void
    {
        $before = $this->snapshot();
        $this->actingAs($this->ceo)->post('/settings/access/levels', ['role' => 'vet', 'area' => 'health', 'level' => 'none'])->assertSessionHasNoErrors();
        $this->assertNotSame($before, $this->snapshot());
        $batch = DB::table('access_changes')->orderByDesc('id')->value('batch');
        $this->assertTrue(DB::table('access_changes')->where('batch', $batch)->where('note', 'like', 'Level: None in Veterinary%')->exists());
        $this->actingAs($this->ceo)->post("/settings/access/history/$batch/rollback")->assertSessionHasNoErrors();
        $this->assertSame($before, $this->snapshot(), 'the roles are exactly as they were');
    }

    public function test_the_preview_shows_gains_and_losses_and_changes_nothing(): void
    {
        $before = $this->snapshot();
        $this->actingAs($this->ceo)->getJson('/settings/access/levels/preview?role=caretaker&area=milking&level=manage')->assertOk()
            ->assertJsonPath('role', 'Caretaker')->assertJsonPath('level', 'Manage')
            ->assertJson(fn ($j) => $j->where('gain', fn ($g) => collect($g)->contains('Milking settings'))->where('lose', [])->etc());
        $this->assertSame($before, $this->snapshot());
        $this->actingAs($this->ceo)->getJson('/settings/access/levels/preview?role=caretaker&area=milking&level=none')->assertOk()
            ->assertJson(fn ($j) => $j->where('lose', fn ($l) => $l->count() >= 2)->etc());
    }

    public function test_locks_hold_the_administrator_settings_vet_locked_actions_and_non_administrators(): void
    {
        $this->actingAs($this->ceo)->post('/settings/access/levels', ['role' => 'ceo', 'area' => 'milking', 'level' => 'none'])->assertStatus(422);
        $this->actingAs($this->ceo)->post('/settings/access/levels', ['role' => 'manager', 'area' => 'settings', 'level' => 'manage'])->assertStatus(422);
        $this->actingAs($this->ceo)->post('/settings/access/levels', ['role' => 'manager', 'area' => 'milking', 'level' => 'bogus'])->assertStatus(422);
        $this->actingAs($this->manager)->post('/settings/access/levels', ['role' => 'caretaker', 'area' => 'milking', 'level' => 'manage'])->assertForbidden();
        $this->actingAs($this->manager)->getJson('/settings/access/levels/preview?role=caretaker&area=milking&level=manage')->assertForbidden();
        // a vet-locked action is not part of any level: closing and reopening Veterinary leaves it with the vet and nobody else
        foreach (['none', 'manage'] as $level) {
            $this->actingAs($this->ceo)->post('/settings/access/levels', ['role' => 'caretaker', 'area' => 'health', 'level' => $level]);
            Tenant::flush();
        }
        $this->assertFalse($this->care->fresh()->passes('clinical'));
        $this->assertTrue(User::make(['role' => 'vet', 'org_id' => $this->org->id])->passes('clinical'));
    }

    public function test_the_levels_tab_lists_every_app_for_every_role_but_the_administrator(): void
    {
        $this->actingAs($this->ceo)->get('/settings/access/tools?tab=levels')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('levels.roles', fn ($r) => collect($r)->pluck('key')->sort()->values()->all() === ['caretaker', 'cleaning', 'feeding', 'finance', 'manager', 'vet'])
            ->where('levels.areas', fn ($a) => collect($a)->pluck('key')->doesntContain('settings') && collect($a)->count() === count(Levels::areas())));
    }
}
