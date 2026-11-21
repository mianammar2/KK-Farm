<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthRecord;
use App\Models\Medicine;
use App\Models\MedicineMovement;
use App\Models\Organization;
use App\Models\ParasiteSample;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WelfareCase;
use App\Support\MedicineStock;
use App\Support\Tenant;
use App\Support\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-200: journey K Parasite Surveillance-to-Control (V3 flow 34). */
class ParasiteControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pooled_test_leads_to_a_targeted_dose_set_by_the_vet_and_a_retest_flags_resistance(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Worm Farm', 'slug' => 'worm-farm'])->id);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $breed = Breed::create(['name' => 'Beetal']);
        $g = [];
        foreach (['W-1', 'W-2', 'W-3'] as $tag) {
            $g[$tag] = Animal::create(['farm_number' => $tag, 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $pen->id]);
        }
        $u = fn (string $role) => User::firstOrCreate(['email' => "$role@worm.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
        [$care, $vet] = [$u('caretaker'), $u('vet')];
        $med = Medicine::create(['code' => 'ALB', 'name' => 'Albendazole', 'category' => 'dewormer', 'unit' => 'ml', 'cost_per_unit_minor' => 2000]);
        MedicineMovement::create(['medicine_id' => $med->id, 'moved_on' => now(), 'type' => 'opening', 'qty' => 100, 'batch' => 'A1', 'expiry_on' => now()->addYear(), 'recorded_by' => 'x']);

        // The vet's guide only highlights.
        $this->actingAs($care)->post('/parasites/guide', ['fec_treat_epg' => 500])->assertForbidden();
        $this->actingAs($vet)->post('/parasites/guide', ['fec_treat_epg' => 500, 'famacha_treat' => 4])->assertSessionHasNoErrors();

        // A pooled pen sample over the guide, and a pale goat: FAMACHA 5 opens a welfare case.
        $this->actingAs($care)->post('/parasites/samples', ['pen_id' => $pen->id, 'sampled_on' => now()->toDateString(), 'reason' => 'suspected', 'epg' => 1800, 'lab' => 'UVAS', 'cost' => 800])->assertSessionHasNoErrors();
        $this->actingAs($care)->post('/parasites/samples', ['animal_id' => $g['W-2']->id, 'sampled_on' => now()->toDateString(), 'reason' => 'clinical', 'famacha' => 5, 'bcs' => 2])->assertSessionHasNoErrors();
        $pooled = ParasiteSample::where('pen_id', $pen->id)->sole();
        $this->assertSame(['body_condition', 'high'], [WelfareCase::sole()->issue, WelfareCase::sole()->severity]);

        // Only the vet decides; a dose needs the vet's withdrawal and a retest date; only goats of the sample.
        $decide = ['decision' => 'treat', 'animal_ids' => [$g['W-1']->id, $g['W-2']->id], 'medicine_id' => $med->id, 'qty_per_goat' => 5, 'route' => 'oral', 'retest_due_on' => now()->addDays(14)->toDateString()];
        $this->actingAs($care)->post("/parasites/samples/{$pooled->id}/decide", $decide)->assertForbidden();
        $this->actingAs($vet)->post("/parasites/samples/{$pooled->id}/decide", $decide)->assertSessionHasErrors('meat_clear_on');
        $outsider = Animal::create(['farm_number' => 'X-9', 'sex' => 'male', 'breed_id' => $breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased']);
        $this->actingAs($vet)->post("/parasites/samples/{$pooled->id}/decide", ['animal_ids' => [$outsider->id], 'meat_clear_on' => now()->addDays(10)->toDateString()] + $decide)->assertSessionHasErrors('animal_ids');
        $this->actingAs($vet)->post("/parasites/samples/{$pooled->id}/decide", ['meat_clear_on' => now()->addDays(10)->toDateString(), 'milk_clear_on' => now()->addDays(4)->toDateString()] + $decide)->assertSessionHasNoErrors();

        // Targeted: 2 of 3 goats dosed from the store, under withdrawal; the third untouched; a retest task.
        $this->assertSame(2, Treatment::where('parasite_sample_id', $pooled->id)->count());
        $this->assertNotNull(Withdrawal::forAnimal($g['W-1']->id)['meat']);
        $this->assertNull(Withdrawal::forAnimal($g['W-3']->id)['meat']);
        $this->assertEqualsWithDelta(90.0, (float) MedicineStock::quantities()[$med->id], 0.01);
        $this->assertTrue(Task::where('title', 'like', "Retest egg count: {$pooled->label()}%")->exists());
        $this->actingAs($vet)->post("/parasites/samples/{$pooled->id}/decide", ['decision' => 'monitor'])->assertStatus(422); // once

        // One blind dose elsewhere counts against the targeted rate.
        HealthRecord::create(['animal_id' => $g['W-3']->id, 'kind' => 'deworming', 'occurred_on' => now(), 'title' => 'Ivermectin', 'recorded_by' => 'x', 'recorded_role' => 'Veterinarian', 'status' => 'closed', 'care_status' => 'administered']);

        // Retest: 1800 → 300 EPG is only 83 %: possible resistance, the vet is alerted.
        $this->actingAs($care)->post('/parasites/samples', ['pen_id' => $pen->id, 'sampled_on' => now()->toDateString(), 'reason' => 'retest', 'retest_of_id' => $pooled->id, 'epg' => 300])->assertSessionHasNoErrors();
        $retest = ParasiteSample::where('retest_of_id', $pooled->id)->sole();
        $this->assertSame([83.3, true], [$retest->reduction_pct, $retest->resistance_flag]);
        $this->assertTrue(Task::where('title', 'like', 'Possible dewormer resistance%')->exists());

        $this->actingAs($vet)->get('/parasites')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('kpi.targetedRate', 67)->where('kpi.resistance', 1)->where('kpi.trend.0.epg', 1050)->where('kpi.awaiting', 2));
    }
}
