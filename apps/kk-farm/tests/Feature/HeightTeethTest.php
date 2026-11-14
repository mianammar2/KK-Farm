<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-262: height with its date (vet and administrator), the vet's Teeth and Height jobs, and what was done in the goat's table. */
class HeightTeethTest extends TestCase
{
    use RefreshDatabase;

    private Animal $goat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Height Farm', 'slug' => 'height-farm'])->id);
        \App\Models\Department::seedDefaults((string) Tenant::current());
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->goat = Animal::create(['farm_number' => 'H-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Beetal'])->id, 'origin' => 'purchased', 'pen_id' => $pen->id, 'birth_date' => now()->subYears(2)])->refresh();
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@height.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_the_vet_and_the_administrator_add_a_height_with_its_date(): void
    {
        $this->actingAs($this->user('caretaker'))->post("/goats/{$this->goat->id}/height", ['height_in' => 28, 'measured_on' => now()->toDateString()])->assertForbidden();
        $this->actingAs($this->user('vet'))->post("/goats/{$this->goat->id}/height", ['height_in' => 27, 'measured_on' => now()->subDays(20)->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($this->user('ceo'))->post("/goats/{$this->goat->id}/height", ['height_in' => 28.5, 'measured_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(72.4, (float) $this->goat->fresh()->height_cm, 0.1, 'the goat follows the latest height');
        $rows = $this->actingAs($this->user('vet'))->get("/goats/{$this->goat->id}")->viewData('page')['props']['records'];
        $this->assertSame([28.5, 27.0], array_map(fn ($r) => $r['height'], $rows));
    }

    public function test_teeth_and_height_are_tasks_jobs_for_the_vet(): void
    {
        foreach (['teeth', 'height'] as $job) {
            $this->assertTrue(\App\Http\Controllers\WorkerController::allowed($this->user('vet'), $job));
            $this->assertTrue(\App\Http\Controllers\WorkerController::allowed($this->user('ceo'), $job));
            $this->assertFalse(\App\Http\Controllers\WorkerController::allowed($this->user('caretaker'), $job));
        }
        $code = array_key_first(\App\Support\Teeth::options());
        $this->actingAs($this->user('vet'))->post("/goats/{$this->goat->id}/teeth", ['teeth' => $code, 'dental_condition' => 'worn', 'examined_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame('worn', $this->goat->fresh()->dental_condition);
    }

    public function test_the_table_shows_what_was_found_and_what_was_done(): void
    {
        \App\Models\HealthRecord::create(['animal_id' => $this->goat->id, 'kind' => 'observation', 'occurred_on' => now()->subDays(3), 'title' => 'Checkup: Temperature 104 °F (high)', 'recorded_by' => 'vet', 'recorded_role' => 'Veterinarian', 'status' => 'open']);
        \App\Models\Treatment::create(['animal_id' => $this->goat->id, 'given_on' => now()->subDays(3), 'product' => 'Oxytetracycline', 'dose' => '5 ml', 'route' => 'IM', 'recorded_by' => 'vet']);
        $row = $this->actingAs($this->user('vet'))->get("/goats/{$this->goat->id}")->viewData('page')['props']['records'][0];
        $this->assertSame(['Temperature 104 °F (high)'], $row['care']);
        $this->assertSame(['Treated: Oxytetracycline 5 ml (IM)'], $row['actions']);
    }

    /** D-270: the field-work reports group by date, open a date for its goats; temperatures only for roles that see health. */
    public function test_records_reports_by_date_and_a_goats_day(): void
    {
        foreach ([[10, 30], [3, 32.5]] as [$d, $kg]) {
            \App\Models\WeightRecord::create(['animal_id' => $this->goat->id, 'measured_on' => now()->subDays($d), 'weight_kg' => $kg, 'measured_by' => 'Ali', 'source' => 'manual']);
        }
        \App\Models\HealthCheckup::create(['animal_id' => $this->goat->id, 'checked_on' => now()->subDays(3), 'temperature_f' => 104.5, 'outcome' => 'monitor', 'examiner' => 'Dr Sana']);
        $day = now()->subDays(3)->toDateString();
        $this->actingAs($this->user('caretaker'))->get('/reports/records?kind=weight')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Reports/Records')->where('rows.0.on', $day)->where('rows.0.change', 2.5)->where('rows.0.breed', 'Beetal')->where('rows.0.sex', 'Female')->where('rows.0.status', 'On farm'));
        $this->actingAs($this->user('caretaker'))->getJson("/reports/records/day?kind=weight&on={$day}")->assertOk()->assertJsonPath('rows.0.number', 'H-1')->assertJsonPath('rows.0.change', 2.5);
        $this->actingAs($this->user('caretaker'))->get('/reports/records?kind=temp')->assertForbidden();
        $this->actingAs($this->user('vet'))->get('/reports/records?kind=temp')->assertOk()->assertInertia(fn ($p) => $p->where('rows.0.fever', true));

        $vet = $this->actingAs($this->user('vet'))->getJson("/goats/{$this->goat->id}/day/{$day}")->assertOk();
        $this->assertSame(32.5, $vet->json('weights.0.kg'));
        $this->assertSame('Watch', $vet->json('checkups.0.result'));
        $this->assertSame([], $this->actingAs($this->user('caretaker'))->getJson("/goats/{$this->goat->id}/day/{$day}")->json('checkups'), 'health is not shown to a caretaker');
    }

    /** D-280: the register page lists every goat with its entry type and when it was entered. */
    public function test_the_register_page_lists_registered_goats(): void
    {
        $this->actingAs($this->user('ceo'))->get('/goats/register')->assertOk()->assertInertia(fn ($p) => $p
            ->where('registered.0.number', 'H-1')->where('registered.0.origin', 'Purchased')->where('registered.0.status', 'On farm')->has('registered.0.entered'));
    }

    /** D-282/D-283: farm-born kids are not on the register page; they are listed in Maternity with mother and father. */
    public function test_farm_born_kids_are_listed_in_maternity_not_on_the_register(): void
    {
        $kid = Animal::create(['farm_number' => 'K-1', 'sex' => 'female', 'breed_id' => $this->goat->breed_id, 'origin' => 'born_on_farm', 'dam_id' => $this->goat->id, 'pen_id' => $this->goat->pen_id, 'birth_date' => now()->subDays(20)]);
        $this->actingAs($this->user('ceo'))->get('/goats/register')->assertInertia(fn ($p) => $p->has('registered', 1)->where('registered.0.number', 'H-1'));
        $this->actingAs($this->user('ceo'))->get('/breeding?tab=kidding')->assertInertia(fn ($p) => $p->has('farmBorn', 1)->where('farmBorn.0.number', 'K-1')->where('farmBorn.0.dam', 'H-1'));
    }

    /** D-288: the Herd Sheet has every goat with its details; health columns only for roles that see health. */
    public function test_the_herd_sheet_lists_every_goat_with_its_details(): void
    {
        \App\Models\WeightRecord::create(['animal_id' => $this->goat->id, 'measured_on' => now()->subDays(10), 'weight_kg' => 30, 'measured_by' => 'x', 'source' => 'manual']);
        \App\Models\WeightRecord::create(['animal_id' => $this->goat->id, 'measured_on' => now(), 'weight_kg' => 32, 'measured_by' => 'x', 'source' => 'manual']);
        $this->actingAs($this->user('vet'))->get('/reports/sheet')->assertOk()->assertInertia(fn ($p) => $p->component('Reports/Sheet')
            ->where('rows.0.tag', 'H-1')->where('rows.0.breed', 'Beetal')->where('rows.0.weighings', 2)->where('rows.0.gainKg', 2)->where('rows.0.adg', 200)->where('rows.0.cases', 0)->where('seeHealth', true));
        $this->actingAs($this->user('caretaker'))->get('/reports/sheet')->assertForbidden();
    }

    /** D-290: a sale says why: business, profit, sick (with the disease) or other (in words); an open case fills in Sick. */
    public function test_a_sale_records_why_and_sick_names_the_disease(): void
    {
        $ppr = \App\Models\Disease::where('code', 'DIS-001')->first() ?? \App\Models\Disease::create(['name' => 'Peste des Petits Ruminants (PPR)', 'code' => 'DIS-001', 'category' => 'infectious', 'active' => true]);
        \App\Models\HealthCase::create(['case_no' => 1, 'animal_id' => $this->goat->id, 'onset_on' => now()->subDays(2), 'condition' => 'Peste des Petits Ruminants (PPR)', 'severity' => 'high', 'status' => 'under_treatment', 'recorded_by' => 'vet']);
        $this->actingAs($this->user('ceo'))->get("/goats/{$this->goat->id}")->assertInertia(fn ($p) => $p->where('goat.saleHint.reason', 'sick')->where('goat.saleHint.diseaseId', $ppr->id));
        $this->actingAs($this->user('ceo'))->post("/goats/{$this->goat->id}/exit", ['reason' => 'sold', 'amount' => 20000])->assertSessionHasErrors('sale_reason');
        $this->actingAs($this->user('ceo'))->post("/goats/{$this->goat->id}/exit", ['reason' => 'sold', 'amount' => 20000, 'sale_reason' => 'sick'])->assertSessionHasErrors('disease_id');
        $this->actingAs($this->user('ceo'))->post("/goats/{$this->goat->id}/exit", ['reason' => 'sold', 'amount' => 20000, 'sale_reason' => 'sick', 'disease_id' => $ppr->id])->assertSessionHasNoErrors();
        $m = \App\Models\AnimalMovement::where('animal_id', $this->goat->id)->where('type', 'sale')->sole();
        $this->assertSame(['sick', $ppr->id, 'Sold: Sick (Peste des Petits Ruminants (PPR))'], [$m->sale_reason, $m->disease_id, $m->reason]);
    }

    /** D-292: the vet ticks symptoms from the master; the case keeps them and its disease; triage rules come with the form. */
    public function test_a_case_keeps_the_ticked_symptoms_and_its_disease(): void
    {
        $ppr = \App\Models\Disease::create(['name' => 'Peste des Petits Ruminants (PPR)', 'code' => 'DIS-001', 'category' => 'infectious', 'active' => true]);
        $ulcer = \App\Models\Symptom::create(['code' => 'SYM-007', 'system' => 'Oral', 'name' => 'Mouth ulcers', 'local_name' => 'Munh ke chhalay']);
        $diarrhea = \App\Models\Symptom::create(['code' => 'SYM-009', 'system' => 'Gastrointestinal', 'name' => 'Diarrhea', 'local_name' => 'Dast']);
        $ppr->symptoms()->attach([$ulcer->id, $diarrhea->id]);
        $this->actingAs($this->user('vet'))->get("/goats/{$this->goat->id}")->assertInertia(fn ($p) => $p
            ->where('healthEntry.symptomMaster.1.name', 'Mouth ulcers')->where('healthEntry.diseaseLinks.0.symptomIds', [$ulcer->id, $diarrhea->id])->where('healthEntry.triage.7.code', 'AL-008'));
        $this->actingAs($this->user('vet'))->post('/health/cases', ['animal_id' => $this->goat->id, 'onset_on' => now()->toDateString(), 'condition' => 'Peste des Petits Ruminants (PPR)',
            'symptoms' => 'Mouth ulcers, Diarrhea', 'symptom_ids' => [$ulcer->id, $diarrhea->id], 'severity' => 'high', 'isolated' => true])->assertSessionHasNoErrors();
        $case = \App\Models\HealthCase::where('animal_id', $this->goat->id)->sole();
        $this->assertSame($ppr->id, $case->disease_id);
        $this->assertEqualsCanonicalizing([$ulcer->id, $diarrhea->id], \Illuminate\Support\Facades\DB::table('health_case_symptoms')->where('health_case_id', $case->id)->pluck('symptom_id')->all());
        $this->assertSame($ppr->id, \App\Support\Movements::saleHint($this->goat->fresh())['diseaseId'], 'the sale form fills in the case disease');
        $this->actingAs($this->user('vet'))->post('/health/symptoms', ['name' => 'Sneezing', 'system' => 'Respiratory', 'local_name' => 'Cheenk', 'disease_ids' => [$ppr->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($ppr->symptoms()->where('name', 'Sneezing')->exists());
        $this->actingAs($this->user('caretaker'))->post('/health/symptoms', ['name' => 'Cough 2', 'system' => 'Respiratory'])->assertForbidden();
    }

    /** D-300: a goat's full profile: identity, growth, breeding; health only with the health right. */
    public function test_the_goat_profile_has_everything_and_health_only_for_those_who_see_it(): void
    {
        \App\Models\WeightRecord::create(['animal_id' => $this->goat->id, 'measured_on' => now()->subDays(10), 'weight_kg' => 30, 'measured_by' => 'x', 'source' => 'manual']);
        \App\Models\WeightRecord::create(['animal_id' => $this->goat->id, 'measured_on' => now(), 'weight_kg' => 32, 'measured_by' => 'x', 'source' => 'manual']);
        \App\Models\HealthCheckup::create(['animal_id' => $this->goat->id, 'checked_on' => now(), 'temperature_f' => 104.2, 'outcome' => 'monitor', 'examiner' => 'Dr Sana']);
        $this->actingAs($this->user('vet'))->getJson("/goats/{$this->goat->id}/profile")->assertOk()
            ->assertJsonPath('number', 'H-1')->assertJsonPath('growth.latestKg', 32)->assertJsonPath('growth.gain.kg', 2)->assertJsonPath('healthDetail.lastCheck.temp', 104.2)->assertJsonPath('healthDetail.lastCheck.fever', true);
        $care = $this->actingAs($this->user('caretaker'))->getJson("/goats/{$this->goat->id}/profile")->assertOk();
        $this->assertNull($care->json('healthDetail'));
    }
}
