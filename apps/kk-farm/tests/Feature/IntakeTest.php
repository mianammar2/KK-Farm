<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthCase;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Support\Intake;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-172: Intake → Registration → RFID → Quarantine → Veterinary Clearance for purchased and transferred goats. */
class IntakeTest extends TestCase
{
    use RefreshDatabase;

    private Animal $goat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Intake Farm', 'slug' => 'intake-farm'])->id);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->goat = Animal::create(['farm_number' => 'NEW-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Beetal'])->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $pen->id]);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@intake.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_a_new_arrival_is_quarantined_with_a_clearance_task_until_the_vet_clears_her(): void
    {
        Intake::open($this->goat, $this->user('manager'));
        $case = HealthCase::sole();
        $this->assertSame([Intake::CONDITION, true, 'monitor'], [$case->condition, $case->isolated, $this->goat->fresh()->health_flag]);
        $this->assertTrue(Task::where('title', 'Intake clearance: NEW-1')->whereDate('due_on', now()->addDays(14))->exists());

        $this->actingAs($this->user('manager'))->get('/intake')->assertInertia(fn (AssertableInertia $p) => $p->where('goats.0.number', 'NEW-1')->where('goats.0.ready', false)->where('canClear', false));
        $this->actingAs($this->user('manager'))->post("/intake/{$case->id}/clear")->assertForbidden();

        $this->actingAs($this->user('vet'))->post("/intake/{$case->id}/clear", ['notes' => 'Dewormed, healthy'])->assertSessionHasNoErrors();
        $this->assertSame(['closed', 'healthy', 'completed'], [$case->fresh()->status, $this->goat->fresh()->health_flag, Task::where('title', 'Intake clearance: NEW-1')->value('status')]);
        $this->actingAs($this->user('vet'))->get('/intake')->assertInertia(fn (AssertableInertia $p) => $p->has('goats', 0));
    }

    public function test_the_quarantine_days_are_a_farm_setting(): void
    {
        $this->actingAs($this->user('caretaker'))->post('/intake/settings', ['quarantine_days' => 21])->assertForbidden();
        $this->actingAs($this->user('ceo'))->post('/intake/settings', ['quarantine_days' => 21])->assertSessionHasNoErrors();
        Intake::open($this->goat, $this->user('ceo'));
        $this->assertTrue(Task::where('title', 'Intake clearance: NEW-1')->whereDate('due_on', now()->addDays(21))->exists());
    }
}
