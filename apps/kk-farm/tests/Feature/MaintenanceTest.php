<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\LedgerEntry;
use App\Models\MaintenanceJob;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-182: asset register, breakdowns, preventive services and repair costs in Accounting. */
class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_breakdown_is_repaired_costed_and_a_service_moves_the_next_date(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Maint Farm', 'slug' => 'maint-farm'])->id);
        $caretaker = User::create(['name' => 'Care', 'email' => 'c@m.test', 'password' => 'x', 'role' => 'caretaker', 'org_id' => Tenant::current()]);
        $manager = User::create(['name' => 'Manager', 'email' => 'm@m.test', 'password' => 'x', 'role' => 'manager', 'org_id' => Tenant::current()]);

        $this->actingAs($caretaker)->post('/maintenance/assets', ['code' => 'PUMP-01', 'name' => 'Water pump', 'category' => 'water_pump', 'status' => 'working'])->assertForbidden();
        $this->actingAs($manager)->post('/maintenance/assets', ['code' => 'PUMP-01', 'name' => 'Water pump', 'category' => 'water_pump', 'status' => 'working', 'service_every_days' => 90])->assertRedirect()->assertSessionHasNoErrors();
        $pump = Asset::sole();
        $this->assertSame(now()->addDays(90)->toDateString(), $pump->next_service_on->toDateString());

        $this->actingAs($caretaker)->post('/maintenance/jobs', ['asset_id' => $pump->id, 'kind' => 'repair', 'problem' => 'No water', 'priority' => 'critical'])->assertSessionHasNoErrors();
        $this->assertSame('under_repair', $pump->fresh()->status);
        $this->assertTrue(Task::where('title', 'Repair (Breakdown): Water pump')->where('type', 'maintenance')->exists());

        $job = MaintenanceJob::sole();
        $this->actingAs($caretaker)->post("/maintenance/jobs/{$job->id}/close", ['done_on' => now()->toDateString(), 'work_done' => 'x'])->assertForbidden();
        $this->actingAs($manager)->post("/maintenance/jobs/{$job->id}/close", ['done_on' => now()->toDateString(), 'work_done' => 'New capacitor', 'cost' => 3500, 'paid_to' => 'Electrician', 'paid' => true])->assertSessionHasNoErrors();
        $this->assertSame(['working', 'completed'], [$pump->fresh()->status, Task::where('title', 'Repair (Breakdown): Water pump')->value('status')]);
        $this->assertEquals(3500, LedgerEntry::where('category', 'Maintenance & Repairs')->value('amount'));

        $this->actingAs($manager)->post('/maintenance/jobs', ['asset_id' => $pump->id, 'kind' => 'preventive', 'problem' => 'Quarterly service', 'priority' => 'medium'])->assertSessionHasNoErrors();
        $svc = MaintenanceJob::where('kind', 'preventive')->sole();
        $this->actingAs($manager)->post("/maintenance/jobs/{$svc->id}/close", ['done_on' => now()->toDateString(), 'work_done' => 'Serviced'])->assertSessionHasNoErrors();
        $this->assertSame(now()->addDays(90)->toDateString(), $pump->fresh()->next_service_on->toDateString());
        $this->actingAs($manager)->get('/maintenance')->assertInertia(fn (AssertableInertia $p) => $p->where('spend.month', 3500)->has('jobs', 2));
    }
}
