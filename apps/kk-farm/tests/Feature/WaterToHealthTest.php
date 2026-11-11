<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Asset;
use App\Models\Breed;
use App\Models\EnvironmentIssue;
use App\Models\LedgerEntry;
use App\Models\MaintenanceJob;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Models\WaterPoint;
use App\Models\WelfareCase;
use App\Support\Tenant;
use App\Support\WaterIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-198: journey I Water-to-Animal Health (V3 flow 32). */
class WaterToHealthTest extends TestCase
{
    use RefreshDatabase;

    private Shed $shed;

    private WaterPoint $trough;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Water Farm', 'slug' => 'water-farm'])->id);
        $this->shed = Shed::create(['code' => 'S1', 'name' => 'Shed 1']);
        $p1 = Pen::create(['shed_id' => $this->shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $p2 = Pen::create(['shed_id' => $this->shed->id, 'name' => 'P02', 'purpose' => 'Bucks']);
        $breed = Breed::create(['name' => 'Beetal']);
        foreach (['G-1' => $p1, 'G-2' => $p1, 'G-3' => $p2] as $tag => $pen) {
            Animal::create(['farm_number' => $tag, 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $pen->id]);
        }
        $pump = Asset::create(['code' => 'PUMP-01', 'name' => 'Pump', 'category' => 'water_pump', 'status' => 'working']);
        $this->trough = WaterPoint::create(['name' => 'P01 trough', 'kind' => 'trough', 'shed_id' => $this->shed->id, 'pen_id' => $p1->id, 'asset_id' => $pump->id]);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@water.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_an_interruption_runs_from_safe_supply_to_vet_check_and_verified_fix(): void
    {
        $care = $this->user('caretaker');
        $manager = $this->user('manager');
        $vet = $this->user('vet');

        // Report: one critical incident, owned tasks, repair job on the pump, welfare case for the 2 goats on that trough.
        $this->actingAs($care)->post('/environment/water/report', ['shed_id' => $this->shed->id, 'problem' => 'interruption', 'point_ids' => [$this->trough->id], 'description' => 'Pump stopped at 6 AM'])->assertSessionHasNoErrors();
        $this->actingAs($care)->post('/environment/water/report', ['shed_id' => $this->shed->id, 'problem' => 'shortage', 'description' => 'Still dry'])->assertSessionHasNoErrors();
        $i = EnvironmentIssue::sole(); // the second report widens the same incident
        $this->assertSame(['water', 'critical', 'interruption', 2], [$i->kind, $i->severity, $i->problem, $i->goats_exposed]);
        $this->assertSame(2, Task::where('priority', 'critical')->count());
        $job = MaintenanceJob::sole();
        $this->assertSame($i->id, $job->environment_issue_id);
        $case = WelfareCase::sole();
        $this->assertSame(['feed_water', 'critical', $case->id], [$case->issue, $case->severity, $i->welfare_case_id]);

        // Emergency tanker: its cost is posted once; approved as safe by someone other than who arranged it.
        $this->actingAs($manager)->post("/environment/issues/{$i->id}/supply", ['source' => 'tanker', 'cost' => 3000])->assertSessionHasNoErrors();
        $this->assertSame(3000.0, (float) LedgerEntry::where('category', 'Water Emergency')->sole()->amount);
        $this->actingAs($care)->post("/environment/issues/{$i->id}/approve-supply")->assertForbidden();
        $this->actingAs($manager)->post("/environment/issues/{$i->id}/approve-supply")->assertSessionHasErrors('supply');
        $this->actingAs($vet)->post("/environment/issues/{$i->id}/approve-supply")->assertSessionHasNoErrors();

        // 90 minutes later the pump runs again: supply restored. Verify waits for the repair and the vet's check.
        $this->travel(90)->minutes();
        $this->actingAs($care)->post("/environment/issues/{$i->id}/resolve", ['resolution' => 'Pump capacitor replaced'])->assertSessionHasNoErrors();
        $this->assertSame(90, WaterIncident::outageMinutes($i->fresh()));
        $this->actingAs($manager)->post("/environment/issues/{$i->id}/verify")->assertSessionHasErrors('verify');
        $job->update(['status' => 'done', 'done_on' => now()->toDateString(), 'work_done' => 'Capacitor', 'cost_minor' => 150000, 'done_by' => 'x']);
        $this->actingAs($care)->post("/environment/issues/{$i->id}/health-check", ['goats_ill' => 0, 'outcome' => 'x'])->assertForbidden();
        $this->actingAs($vet)->post("/environment/issues/{$i->id}/health-check", ['goats_ill' => 1, 'outcome' => 'G-2 mildly dehydrated, oral fluids'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/environment/issues/{$i->id}/verify")->assertSessionHasNoErrors();

        $this->assertSame('verified', $i->fresh()->status);
        $this->assertSame('closed', $case->fresh()->status);
        $this->assertStringContainsString('1 ill', $case->fresh()->closure_evidence);
        $this->assertSame(300000 + 150000, WaterIncident::cost($i->fresh()));
        $this->actingAs($manager)->get('/environment?tab=issues')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('waterKpi.outageMinutes', 90)->where('waterKpi.verifiedPct', 100)->where('waterKpi.cost', 4500)->where('issues.0.water.supply.approvedBy', 'Test vet'));
    }

    public function test_an_unsafe_lab_test_opens_contamination_that_needs_a_safe_retest_to_restore(): void
    {
        $vet = $this->user('vet');
        $this->actingAs($vet)->post('/environment/water/tests', ['water_point_id' => $this->trough->id, 'sampled_on' => now()->toDateString(), 'lab' => 'PCRWR', 'coliform_cfu' => 40,
            'verdict' => 'unsafe', 'cost' => 1500])->assertSessionHasNoErrors();
        $i = EnvironmentIssue::sole();
        $this->assertSame(['contamination', [$this->trough->id]], [$i->problem, $i->point_ids]);
        $this->assertNotNull($i->water_test_id);
        $this->assertSame(1500.0, (float) LedgerEntry::where('category', 'Water Testing')->sole()->amount);
        $this->assertStringContainsString('clean and disinfect', MaintenanceJob::sole()->problem);

        $this->actingAs($vet)->post("/environment/issues/{$i->id}/resolve", ['resolution' => 'Tank cleaned'])->assertSessionHasErrors('resolution');
        $this->actingAs($vet)->post('/environment/water/tests', ['water_point_id' => $this->trough->id, 'sampled_on' => now()->toDateString(), 'coliform_cfu' => 0, 'verdict' => 'safe'])->assertSessionHasNoErrors();
        $this->actingAs($vet)->post("/environment/issues/{$i->id}/resolve", ['resolution' => 'Tank cleaned and chlorinated'])->assertSessionHasNoErrors();
        $this->assertNotNull($i->fresh()->restored_at);
        $this->assertSame(1, EnvironmentIssue::count());
    }
}
