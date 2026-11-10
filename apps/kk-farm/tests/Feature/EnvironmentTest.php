<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\EnvironmentIssue;
use App\Models\EnvironmentRound;
use App\Models\LedgerEntry;
use App\Models\MaintenanceJob;
use App\Models\Organization;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Models\WaterPoint;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-186: App 20 along V3 flow 20, with BR-021 (no water = critical) and BR-022 (configurable heat limit). */
class EnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private Shed $shed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Env Farm', 'slug' => 'env-farm'])->id);
        $this->shed = Shed::create(['code' => 'S1', 'name' => 'KKF-BDU-01']);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@env.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function round(array $extra = [], string $role = 'caretaker')
    {
        return $this->actingAs($this->user($role))->post('/environment/rounds', $extra + ['shed_id' => $this->shed->id, 'trigger' => 'scheduled', 'water' => [],
            'water_ok' => true, 'ventilation_ok' => true, 'power_ok' => true, 'waste_ok' => true]);
    }

    public function test_a_normal_round_records_climate_and_utility_cost(): void
    {
        $this->round(['temperature_c' => 25, 'humidity_pct' => 50, 'utility_kind' => 'electricity', 'utility_cost' => 1200])->assertSessionHasNoErrors();
        $r = EnvironmentRound::sole();
        $this->assertSame([false, 0], [$r->climate_risk, EnvironmentIssue::count()]);
        $this->assertEqualsWithDelta(EnvironmentRound::thi(25, 50), $r->thi, 0.01);
        $this->assertEquals(1200, LedgerEntry::where('category', 'Utilities')->value('amount'));
    }

    public function test_no_water_is_critical_and_opens_a_repair_job_on_the_pump(): void
    {
        $pump = Asset::create(['code' => 'PUMP-01', 'name' => 'Pump', 'category' => 'water_pump', 'status' => 'working']);
        $wp = WaterPoint::create(['name' => 'P01 trough', 'kind' => 'trough', 'shed_id' => $this->shed->id, 'asset_id' => $pump->id]);
        $this->round(['water' => [['water_point_id' => $wp->id, 'available' => false, 'quality' => 'empty']]])->assertSessionHasNoErrors();
        $i = EnvironmentIssue::sole();
        $this->assertSame(['water', 'critical'], [$i->kind, $i->severity]);
        $this->assertSame(2, Task::where('priority', 'critical')->count()); // manager and vet
        $this->assertSame(['repair', 'under_repair'], [MaintenanceJob::sole()->kind, $pump->fresh()->status]);

        $this->actingAs($this->user('caretaker'))->post("/environment/issues/{$i->id}/resolve", ['resolution' => 'Tanker water, pump fixed'])->assertSessionHasNoErrors();
        $this->actingAs($this->user('caretaker'))->post("/environment/issues/{$i->id}/verify")->assertForbidden();
        $this->actingAs($this->user('manager'))->post("/environment/issues/{$i->id}/verify")->assertSessionHasNoErrors();
        $this->assertSame('verified', $i->fresh()->status);
    }

    public function test_heat_above_the_farm_limit_and_waste_problems_raise_issues(): void
    {
        $this->actingAs($this->user('caretaker'))->post('/environment/settings', ['heat_stress_thi' => 75])->assertForbidden();
        $this->actingAs($this->user('vet'))->post('/environment/settings', ['heat_stress_thi' => 75])->assertSessionHasNoErrors();
        $this->round(['temperature_c' => 33, 'humidity_pct' => 60, 'waste_ok' => false])->assertSessionHasErrors('waste_notes');
        $this->round(['temperature_c' => 33, 'humidity_pct' => 60, 'waste_ok' => false, 'waste_notes' => 'Drain blocked'])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['heat', 'waste'], EnvironmentIssue::pluck('kind')->all());
        $this->assertTrue(EnvironmentRound::sole()->climate_risk);
        $this->actingAs($this->user('manager'))->get('/environment')->assertInertia(fn (AssertableInertia $p) => $p->where('threshold', 75)->where('kpi.heatExceptions', 1)->where('sheds.0.openIssues', 2));
    }
}
