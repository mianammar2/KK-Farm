<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\BreedingApproval;
use App\Models\BreedingRule;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Designations;
use App\Support\Eligibility;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-231: breeding eligibility checks the rules and asks for approval; only an authorised person approves (UC-01, 02, 03, 05). */
class EligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_qualified_is_not_approved_and_a_person_decides(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Elig Farm', 'slug' => 'elig-farm'])->id);
        // D-241: approving goats for breeding (and its rules) is vet-locked; the manager only views
        $manager = User::create(['email' => 'm@elig.test', 'name' => 'Vet', 'password' => 'x', 'role' => 'vet', 'org_id' => Tenant::current(), 'active' => true]);
        $realManager = User::create(['email' => 'mm@elig.test', 'name' => 'Manager', 'password' => 'x', 'role' => 'manager', 'org_id' => Tenant::current(), 'active' => true]);
        $caretaker = User::create(['email' => 'c@elig.test', 'name' => 'Care', 'password' => 'x', 'role' => 'caretaker', 'org_id' => Tenant::current(), 'active' => true]);
        $mc = Breed::create(['name' => 'Makhi Cheeni']);
        $goat = function (string $tag, string $sex, int $months, ?float $kg, array $more = []) use ($mc) {
            $g = Animal::create($more + ['farm_number' => $tag, 'sex' => $sex, 'breed_id' => $mc->id, 'birth_date' => now()->subMonths($months), 'origin' => 'purchased', 'status' => 'active', 'animal_purpose' => 'replacement']);
            if ($kg !== null) {
                WeightRecord::create(['animal_id' => $g->id, 'measured_on' => now()->subDays(2), 'weight_kg' => $kg, 'body_score' => 3, 'measured_by' => 'Test', 'source' => 'manual']);
            }

            return $g->fresh();
        };

        // UC-01: female, 8 months, 35 KG: Grower; a maturity review only, never qualified.
        $uc01 = Eligibility::check($goat('UC01', 'female', 8, 35));
        $this->assertSame(['not_eligible', true, false], [$uc01['status'], $uc01['review'], $uc01['checks']['age']['ok']]);
        // UC-02: 16 months, 2 teeth, 28 KG: ADT-T02 but below the weight rule.
        $uc02g = $goat('UC02', 'female', 16, 28, ['teeth' => 'T02']);
        $uc02 = Eligibility::check($uc02g);
        $this->assertSame(['ADT-T02', 'not_eligible', false, true], [$uc02g->classCode(), $uc02['status'], $uc02['checks']['weight']['ok'], $uc02['review']]);
        // UC-03: Makhi Cheeni, 16 months, 37 KG, 2 teeth, all checks passed: qualified and an approval task, not a breeder yet.
        $uc03 = $goat('UC03', 'female', 16, 37, ['teeth' => 'T02']);
        $this->assertSame('qualified', Eligibility::check($uc03)['status']);
        $this->assertSame(1, Task::where('animal_id', $uc03->id)->where('title', 'Breeding approval: UC03')->count(), 'the weighing created the task');
        $this->assertSame(['RD'], Designations::codes($uc03));

        // A breed rule wins over the all-breeds rule.
        $this->actingAs($manager)->post('/breeding/eligibility/rules', ['breed_id' => $mc->id, 'sex' => 'female', 'min_age_months' => 12, 'min_weight_kg' => 38, 'min_mature_pct' => 65, 'weight_max_age_days' => 30])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertSame('not_eligible', Eligibility::check($uc03)['status']);
        $this->actingAs($manager)->delete('/breeding/eligibility/rules/'.BreedingRule::where('breed_id', $mc->id)->value('id'))->assertSessionHasNoErrors();
        Tenant::flush();

        $this->actingAs($manager)->get('/breeding/eligibility')->assertOk()->assertInertia(fn ($p) => $p->component('Breeding/Eligibility')->where('rows', fn ($r) => collect($r)->firstWhere('number', 'UC03')['status'] === 'qualified')->has('rules', 2));
        $this->actingAs($manager)->get('/notifications')->assertJsonFragment(['key' => 'breeding_approval']);

        // Only an authorised person approves, and only after confirming the exam and the line.
        $this->actingAs($caretaker)->post("/breeding/eligibility/{$uc03->id}", ['decision' => 'approved', 'repro_exam_passed' => true, 'genetics_approved' => true])->assertForbidden();
        $this->actingAs($realManager)->post("/breeding/eligibility/{$uc03->id}", ['decision' => 'approved', 'repro_exam_passed' => true, 'genetics_approved' => true])->assertForbidden();
        $this->actingAs($realManager)->get('/breeding/eligibility')->assertOk();
        $this->actingAs($manager)->post("/breeding/eligibility/{$uc03->id}", ['decision' => 'approved'])->assertSessionHasErrors('repro_exam_passed');
        $this->actingAs($manager)->post("/breeding/eligibility/{$uc03->id}", ['decision' => 'approved', 'repro_exam_passed' => true, 'genetics_approved' => true])->assertSessionHasNoErrors();
        $uc03->refresh();
        Tenant::flush();
        $this->assertSame(['breeding', 'ready', ['BD'], 'approved'], [$uc03->animal_purpose, $uc03->repro_status, Designations::codes($uc03), Eligibility::check($uc03)['status']]);
        $this->assertSame('completed', Task::where('animal_id', $uc03->id)->value('status'));

        // Approving a goat that fails a check is an override and needs a reason.
        $this->actingAs($manager)->post("/breeding/eligibility/{$uc02g->id}", ['decision' => 'approved', 'repro_exam_passed' => true, 'genetics_approved' => true])->assertSessionHasErrors('reason');
        $this->actingAs($manager)->post("/breeding/eligibility/{$uc02g->id}", ['decision' => 'approved', 'repro_exam_passed' => true, 'genetics_approved' => true, 'reason' => 'Small line, vet satisfied with frame'])->assertSessionHasNoErrors();
        $this->assertTrue(BreedingApproval::where('animal_id', $uc02g->id)->value('override'));

        // UC-05: a male that fails the breeding assessment: fattening assessment recommended (not automatic).
        $buck = $goat('UC05', 'male', 14, 36);
        $this->actingAs($manager)->post("/breeding/eligibility/{$buck->id}", ['decision' => 'rejected', 'reason' => 'Poor conformation'])->assertSessionHasNoErrors();
        Tenant::flush();
        $check = Eligibility::check($buck->fresh());
        $this->assertSame(['rejected', 'Fattening assessment recommended', 'replacement'], [$check['status'], $check['recommendation'], $buck->fresh()->animal_purpose]);

        // Castrated males and does already breeding before this change are not candidates.
        $this->assertSame('not_candidate', Eligibility::check($goat('WTH1', 'male', 14, 36, ['castrated_on' => now()->subMonth()]))['status']);
        $this->assertSame('breeder', Eligibility::check($goat('OLD1', 'female', 30, 40, ['animal_purpose' => 'breeding']))['status']);
        $this->actingAs($manager)->get("/goats/{$uc02g->id}")->assertInertia(fn ($p) => $p->where('eligibility.status', 'approved'));
    }
}
