<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\BreederSelection;
use App\Models\Breed;
use App\Models\GrowthIntervention;
use App\Models\HealthRecord;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Growth;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-191: V3 journey B Birth-to-Breeder: growth vs plan, interventions with follow-up, BR-005 breeder selection, neonatal care. */
class BirthToBreederTest extends TestCase
{
    use RefreshDatabase;

    private Breed $breed;

    private Pen $pen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Kid Farm', 'slug' => 'kid-farm'])->id);
        $this->breed = Breed::create(['name' => 'Beetal']);
        $this->pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Growers']);
        Growth::flush();
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@kid.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function goat(string $tag, int $ageDays, ?float $kg, array $extra = []): Animal
    {
        $a = Animal::create($extra + ['farm_number' => $tag, 'sex' => 'female', 'breed_id' => $this->breed->id, 'birth_date' => now()->subDays($ageDays), 'origin' => 'born_on_farm', 'pen_id' => $this->pen->id, 'status' => 'active', 'birth_weight_kg' => 3]);
        if ($kg !== null) {
            WeightRecord::create(['animal_id' => $a->id, 'measured_on' => now()->subDays(2), 'weight_kg' => $kg, 'measured_by' => 'x']);
        }

        return $a;
    }

    public function test_targets_interpolate_and_a_goat_below_target_gets_an_intervention_and_a_follow_up(): void
    {
        $this->assertSame(13.0, Growth::target($this->breed->id, 75)); // halfway 60 → 90 days: 11 → 15 KG
        $kid = $this->goat('K-1', 92, 10.0); // ~15 KG target at 90 days: 67%
        $this->actingAs($this->user('caretaker'))->get('/growth')->assertInertia(fn (AssertableInertia $p) => $p->component('BirthToBreeder')->where('growth.0.below', true)->where('growth.0.pct', 67));

        $this->actingAs($this->user('vet'))->post("/growth/{$kid->id}/intervene", ['kind' => 'veterinary', 'action' => 'Faecal egg count', 'follow_up_on' => now()->addDays(14)->toDateString()])->assertSessionHasNoErrors();
        $this->assertTrue(HealthRecord::where('title', 'Poor growth: veterinary review')->exists());
        $this->assertSame(2, Task::where('animal_id', $kid->id)->count()); // the vet's task and the follow-up weighing
        $this->actingAs($this->user('vet'))->post("/growth/{$kid->id}/intervene", ['kind' => 'nutrition', 'action' => 'x', 'follow_up_on' => now()->addDays(7)->toDateString()])->assertStatus(422);

        $i = GrowthIntervention::sole();
        $this->actingAs($this->user('caretaker'))->post("/growth/interventions/{$i->id}/follow-up", ['weight_kg' => 11])->assertSessionHasNoErrors();
        $this->assertSame('not_improved', $i->fresh()->status);
        $this->actingAs($this->user('vet'))->post("/growth/{$kid->id}/intervene", ['kind' => 'nutrition', 'action' => 'Creep feed', 'follow_up_on' => now()->addDays(7)->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($this->user('caretaker'))->post('/growth/interventions/'.GrowthIntervention::latest('id')->first()->id.'/follow-up', ['weight_kg' => 15])->assertSessionHasNoErrors();
        $this->assertSame('improved', GrowthIntervention::latest('id')->first()->status);
    }

    public function test_breeder_selection_follows_the_checks_and_a_person_decides(): void
    {
        $good = $this->goat('B-1', 200, 26.0, ['sire_tag' => 'S-9', 'dam_tag' => 'D-9']); // target at 200 days ≈ 25.3 KG
        $weak = $this->goat('B-2', 200, 18.0);

        $this->actingAs($this->user('caretaker'))->post("/growth/{$good->id}/select", ['decision' => 'replacement'])->assertForbidden();
        $this->actingAs($this->user('vet'))->get('/growth')->assertInertia(fn (AssertableInertia $p) => $p->where('candidates.0.number', 'B-1')->where('candidates.0.eligible', true)->where('candidates.1.eligible', false));
        $this->actingAs($this->user('vet'))->post("/growth/{$good->id}/select", ['decision' => 'replacement'])->assertSessionHasNoErrors();
        $this->assertSame(['replacement', 'breeding_selected'], [$good->fresh()->animal_purpose, $good->fresh()->kid_stage]);

        $this->actingAs($this->user('vet'))->post("/growth/{$weak->id}/select", ['decision' => 'replacement'])->assertSessionHasErrors('reason');
        $this->actingAs($this->user('manager'))->post("/growth/{$weak->id}/select", ['decision' => 'sale'])->assertSessionHasNoErrors();
        $this->assertSame('sale', $weak->fresh()->animal_purpose);
        $this->assertFalse(BreederSelection::where('animal_id', $weak->id)->sole()->override);
        $this->actingAs($this->user('vet'))->get('/growth')->assertInertia(fn (AssertableInertia $p) => $p->has('candidates', 0)->where('kpi.selectionRate', 50));
    }

    public function test_growth_plans_never_go_down_and_a_breed_plan_wins(): void
    {
        $this->actingAs($this->user('manager'))->post('/growth/plan', ['points' => [['age_days' => 0, 'target_kg' => 3], ['age_days' => 90, 'target_kg' => 2]]])->assertSessionHasErrors('points');
        $this->actingAs($this->user('manager'))->post('/growth/plan', ['breed_id' => $this->breed->id, 'points' => [['age_days' => 0, 'target_kg' => 4], ['age_days' => 100, 'target_kg' => 24]]])->assertSessionHasNoErrors();
        Growth::flush();
        $this->assertSame(14.0, Growth::target($this->breed->id, 50));
        $this->assertSame(11.0, Growth::target(null, 60)); // farm defaults for other breeds
    }

    public function test_a_kid_that_is_not_stable_goes_to_neonatal_care_and_the_vet(): void
    {
        $kid = $this->goat('N-1', 1, null, ['birth_outcome' => 'BAW']);
        $this->actingAs($this->user('caretaker'))->post('/breeding/kid-care', ['kid_id' => $kid->id, 'kind' => 'neonatal_care', 'given_at' => now()->subMinute()->toDateTimeString(), 'notes' => 'Cold, not standing'])->assertSessionHasNoErrors();
        $this->assertSame('NCU-01', $kid->fresh()->pen->name);
        $this->assertTrue(Task::where('title', 'Neonatal care: N-1')->where('priority', 'critical')->exists());
        $this->assertTrue(HealthRecord::where('animal_id', $kid->id)->where('title', 'Newborn not stable: neonatal care')->exists());
    }
}
