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
use App\Models\WelfareCase;
use App\Support\SaleCheck;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-199: journey J Welfare-to-Treatment (V3 flow 33): vet diagnosis and humane handling before routine work. */
class WelfareToTreatmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lame_doe_is_held_from_routine_work_treated_under_the_vet_and_closed_with_recurrence_tracked(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Care Farm', 'slug' => 'care-farm'])->id);
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $hospital = Pen::create(['shed_id' => $shed->id, 'name' => 'H1', 'purpose' => 'Hospital']);
        $breed = Breed::create(['name' => 'Beetal']);
        $doe = Animal::create(['farm_number' => 'D-7', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $buck = Animal::create(['farm_number' => 'B-1', 'sex' => 'male', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $pen->id]);
        $u = fn (string $role) => User::firstOrCreate(['email' => "$role@care.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
        [$care, $vet, $manager] = [$u('caretaker'), $u('vet'), $u('manager')];
        $routine = Task::create(['title' => 'Weigh D-7', 'type' => 'feeding', 'priority' => 'medium', 'status' => 'open', 'due_on' => now()->toDateString(), 'animal_id' => $doe->id]);

        // A high lameness case: routine handling stops and her routine task is held.
        $this->actingAs($care)->post('/welfare/cases', ['animal_id' => $doe->id, 'issue' => 'lameness', 'severity' => 'high', 'triage' => 'Not bearing weight on left fore'])->assertSessionHasNoErrors();
        $case = WelfareCase::sole();
        $this->assertSame('blocked', $routine->fresh()->status);
        $this->actingAs($care)->post('/worker/weigh', ['animal_id' => $doe->id, 'weight_kg' => 40])->assertSessionHasErrors('animal_id');
        $this->actingAs($manager)->post('/breeding/matings', ['dam_id' => $doe->id, 'sire_id' => $buck->id, 'mated_on' => now()->toDateString(), 'method' => 'natural'])->assertSessionHasErrors('dam_id');
        $this->assertContains("Welfare case {$case->label()} open", SaleCheck::blockers($doe->fresh()));

        // Referral, then the vet's case is linked as the diagnosis.
        $this->actingAs($care)->post("/welfare/cases/{$case->id}/step", ['kind' => 'vet_referral', 'notes' => 'Urgent'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->get('/welfare')->assertInertia(fn (AssertableInertia $p) => $p->where('kpi.awaitingVet', 1)->where('kpi.held', 1));
        $this->actingAs($vet)->post('/health/cases', ['animal_id' => $doe->id, 'onset_on' => now()->toDateString(), 'condition' => 'Foot abscess', 'severity' => 'moderate'])->assertSessionHasNoErrors();
        $health = HealthCase::sole();
        $this->assertSame($health->id, $case->fresh()->health_case_id);

        // Housing: moved to the hospital pen. An assigned hoof action creates a task for the caretaker.
        $this->actingAs($care)->post("/welfare/cases/{$case->id}/step", ['kind' => 'housing', 'pen_id' => $hospital->id])->assertSessionHasNoErrors();
        $this->assertSame($hospital->id, $doe->fresh()->pen_id);
        $this->actingAs($vet)->post("/welfare/cases/{$case->id}/step", ['kind' => 'hoof', 'notes' => 'Footbath daily', 'assigned_user_id' => $care->id, 'due_on' => now()->addDay()->toDateString()])->assertSessionHasNoErrors();
        $this->assertTrue(Task::where('user_id', $care->id)->where('title', 'like', 'Hoof Trimming%')->where('status', 'open')->exists());

        // Improving, but the vet's case is still active: it cannot close yet.
        $this->actingAs($care)->post("/welfare/cases/{$case->id}/step", ['kind' => 'follow_up', 'improved' => true])->assertSessionHasNoErrors();
        $this->actingAs($vet)->post("/welfare/cases/{$case->id}/step", ['kind' => 'close', 'evidence' => 'Walking sound'])->assertSessionHasErrors('kind');

        // Only the vet clears routine handling; then her task resumes.
        $this->actingAs($care)->post("/welfare/cases/{$case->id}/step", ['kind' => 'handling_ok'])->assertForbidden();
        $this->actingAs($vet)->post("/health/cases/{$health->id}", ['status' => 'recovered'])->assertSessionHasNoErrors();
        $this->assertSame('follow_up', $case->fresh()->status);
        $this->actingAs($vet)->post("/welfare/cases/{$case->id}/step", ['kind' => 'handling_ok', 'notes' => 'Light handling fine'])->assertSessionHasNoErrors();
        $this->assertSame('open', $routine->fresh()->status);
        $this->actingAs($care)->post('/worker/weigh', ['animal_id' => $doe->id, 'weight_kg' => 40])->assertSessionHasNoErrors();
        $this->actingAs($vet)->post("/welfare/cases/{$case->id}/step", ['kind' => 'close', 'evidence' => 'Walking sound, abscess healed'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $case->fresh()->status);

        // Lame again within 90 days: a repeat, raised to high so the vet sees it.
        $this->actingAs($care)->post('/welfare/cases', ['animal_id' => $doe->id, 'issue' => 'lameness', 'severity' => 'medium'])->assertSessionHasNoErrors();
        $again = WelfareCase::latest('id')->first();
        $this->assertSame([$case->id, 'high'], [$again->recurrence_of_id, $again->severity]);
        $this->actingAs($manager)->get('/welfare')->assertInertia(fn (AssertableInertia $p) => $p->where('kpi.recurrence', 50)->where('kpi.lameness', 2)->where('cases.0.repeatOf', $case->label()));
    }
}
