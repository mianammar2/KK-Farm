<?php

namespace Tests\Feature;

use App\Models\ChecklistRun;
use App\Models\ChecklistTemplate;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\ShiftHandover;
use App\Models\Task;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-180: shift checklists, incident register and shift handovers. */
class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@ops.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Ops Farm', 'slug' => 'ops-farm'])->id);
    }

    public function test_staff_tick_a_checklist_and_a_supervisor_verifies_it(): void
    {
        $t = ChecklistTemplate::create(['name' => 'Morning Checklist', 'shift' => 'morning', 'items' => ['Count goats', 'Water full']]);
        $this->actingAs($this->user('caretaker'))->post("/operations/checklists/{$t->id}/tick", ['done' => [0]])->assertSessionHasNoErrors();
        $run = ChecklistRun::sole();
        $this->assertNull($run->completed_at);
        $this->actingAs($this->user('caretaker'))->post("/operations/checklists/{$t->id}/tick", ['done' => [0, 1]])->assertSessionHasNoErrors();
        $this->assertSame('Test caretaker', $run->fresh()->completed_by);
        $this->actingAs($this->user('caretaker'))->post("/operations/runs/{$run->id}/verify")->assertForbidden();
        $this->actingAs($this->user('manager'))->post("/operations/runs/{$run->id}/verify")->assertSessionHasNoErrors();
        $this->actingAs($this->user('caretaker'))->post("/operations/checklists/{$t->id}/tick", ['done' => []])->assertStatus(422); // locked after verification
        $this->actingAs($this->user('caretaker'))->get('/operations')->assertInertia(fn (AssertableInertia $p) => $p->where('checklists.0.run.verifiedBy', 'Test manager'));
    }

    public function test_a_serious_incident_raises_a_task_and_is_resolved_by_a_supervisor(): void
    {
        $this->actingAs($this->user('caretaker'))->post('/operations/incidents', ['occurred_at' => now()->subHour()->format('Y-m-d H:i'), 'kind' => 'water_failure', 'severity' => 'high', 'description' => 'Pump stopped'])->assertSessionHasNoErrors();
        $i = Incident::sole();
        $this->assertTrue(Task::where('title', 'Incident: Water or Power Failure')->where('priority', 'high')->exists());
        $this->actingAs($this->user('caretaker'))->post("/operations/incidents/{$i->id}/resolve", ['resolution' => 'x'])->assertForbidden();
        $this->actingAs($this->user('manager'))->post("/operations/incidents/{$i->id}/resolve", ['resolution' => 'Pump repaired'])->assertSessionHasNoErrors();
        $this->assertSame(['resolved', 'Test manager'], [$i->fresh()->status, $i->fresh()->resolved_by]);
    }

    public function test_a_handover_is_saved_with_who_handed_over(): void
    {
        $this->actingAs($this->user('caretaker'))->post('/operations/handovers', ['shift' => 'evening', 'to_name' => 'Night guard', 'summary' => 'All fed', 'pending' => 'P02 water low'])->assertSessionHasNoErrors();
        $this->assertSame(['Test caretaker', 'Night guard'], [ShiftHandover::sole()->from_name, ShiftHandover::sole()->to_name]);
    }
}
