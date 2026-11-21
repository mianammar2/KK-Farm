<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthCase;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Treatment;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-368: Medication: scan the goat, the date and time given, why, then every medicine given at that time. */
class MedicationTest extends TestCase
{
    use RefreshDatabase;

    private Animal $goat;

    private Animal $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Med Farm', 'slug' => 'med-farm'])->id);
        $breed = Breed::create(['name' => 'Beetal', 'default_gestation_days' => 150]);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->goat = Animal::create(['farm_number' => 'G-1', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $pen->id]);
        $this->other = Animal::create(['farm_number' => 'G-2', 'sex' => 'male', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $pen->id]);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@med.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function body(array $extra = []): array
    {
        return $extra + ['animal_id' => $this->goat->id, 'given_on' => \App\Support\Feed::now()->subMinutes(5)->toDateString(), 'given_time' => \App\Support\Feed::now()->subMinutes(5)->format('H:i'), 'reason' => 'Pneumonia', 'severity' => 'high', 'items' => [
            ['product' => 'Oxytetracycline', 'dose' => '5 ml', 'route' => 'injectable', 'meat_clear_on' => now()->addDays(28)->toDateString(), 'milk_clear_on' => now()->addDays(7)->toDateString()],
            ['product' => 'Meloxicam', 'dose' => '2 ml', 'route' => 'injectable', 'frequency' => 'once', 'no_withdrawal' => true],
        ]];
    }

    public function test_a_vet_records_two_medicines_under_a_new_reason_with_the_time(): void
    {
        $this->actingAs($this->user('caretaker'))->post('/health/medications', $this->body())->assertForbidden();
        $this->actingAs($this->user('vet'))->post('/health/medications', $this->body())->assertSessionHasNoErrors();

        $case = HealthCase::where('animal_id', $this->goat->id)->sole();
        $this->assertSame('Pneumonia', $case->condition);
        $this->assertSame('under_treatment', $case->status);
        $t = Treatment::where('health_case_id', $case->id)->orderBy('id')->get();
        $this->assertSame(['Oxytetracycline', 'Meloxicam'], $t->pluck('product')->all());
        $this->assertNotNull($t[0]->given_at);
        $this->assertSame(\App\Support\Feed::now()->subMinutes(5)->format('H:i'), $t[0]->given_at->setTimezone(\App\Support\Feed::now()->getTimezone())->format('H:i'));
    }

    public function test_it_goes_under_an_open_case_of_that_goat_and_nothing_is_missed(): void
    {
        $vet = $this->user('vet');
        $this->actingAs($vet)->post('/health/medications', $this->body())->assertSessionHasNoErrors();
        $case = HealthCase::where('animal_id', $this->goat->id)->sole();

        // under the same case: no new case
        $this->actingAs($vet)->post('/health/medications', $this->body(['case_id' => $case->id, 'reason' => null, 'severity' => null]))->assertSessionHasNoErrors();
        $this->assertSame(1, HealthCase::where('animal_id', $this->goat->id)->count());
        $this->assertSame(4, Treatment::where('health_case_id', $case->id)->count());

        // another goat's case, a medicine without withdrawal dates, no medicine, a time later than now: refused, nothing saved
        $this->actingAs($vet)->post('/health/medications', $this->body(['animal_id' => $this->other->id, 'case_id' => $case->id]))->assertSessionHasErrors('case_id');
        $this->actingAs($vet)->post('/health/medications', $this->body(['items' => []]))->assertSessionHasErrors('items');
        $this->actingAs($vet)->post('/health/medications', $this->body(['given_on' => \App\Support\Feed::now()->addHours(2)->toDateString(), 'given_time' => \App\Support\Feed::now()->addHours(2)->format('H:i')]))->assertSessionHasErrors();
        $this->assertSame(4, Treatment::count());
        $this->assertSame(1, HealthCase::count());
    }

    public function test_a_medicine_keeps_its_standard_dose_and_a_treatment_keeps_its_dose_time(): void // D-369
    {
        $vet = $this->user('vet');
        $this->actingAs($vet)->post('/health/medicines', ['code' => 'OXY', 'name' => 'Oxytetracycline LA', 'category' => 'antibiotic', 'unit' => 'ml', 'opening_qty' => 100,
            'dose_qty' => 5, 'dose_route' => 'injectable', 'dose_frequency' => 'q72h', 'dose_days' => 6, 'meat_withdrawal_days' => 28, 'milk_withdrawal_days' => 7])->assertSessionHasNoErrors();
        $this->actingAs($vet)->get('/health')->assertInertia(fn ($p) => $p->where('medicines.0.dose', ['qty' => 5, 'route' => 'injectable', 'frequency' => 'q72h', 'days' => 6, 'meatDays' => 28, 'milkDays' => 7])->etc());

        $this->actingAs($vet)->post('/health/medications', $this->body())->assertSessionHasNoErrors();
        $case = HealthCase::where('animal_id', $this->goat->id)->sole();
        $at = \App\Support\Feed::now()->subMinutes(30);
        $this->actingAs($vet)->post("/health/cases/{$case->id}/treatments", ['given_on' => $at->toDateString(), 'given_time' => $at->format('H:i'), 'product' => 'Vitamin B', 'dose' => '2 ml', 'route' => 'injectable', 'no_withdrawal' => true])->assertSessionHasNoErrors();
        $this->assertSame($at->format('H:i'), Treatment::where('product', 'Vitamin B')->sole()->given_at->setTimezone($at->getTimezone())->format('H:i'));
    }

    public function test_only_the_medicine_and_quantity_are_needed(): void // D-373
    {
        $this->actingAs($this->user('vet'))->post('/health/medications', $this->body(['items' => [['product' => 'Loxin', 'dose' => '3 CC', 'route' => 'injectable']]]))->assertSessionHasNoErrors();
        $t = Treatment::where('product', 'Loxin')->sole();
        $this->assertNull($t->meat_clear_on);
        $this->assertSame('3 CC', $t->dose);
    }

    public function test_without_a_reason_it_goes_under_a_case_named_after_the_medicines(): void // D-370
    {
        $this->actingAs($this->user('vet'))->post('/health/medications', $this->body(['reason' => null, 'severity' => null]))->assertSessionHasNoErrors();
        $case = HealthCase::where('animal_id', $this->goat->id)->sole();
        $this->assertSame('Medication: Oxytetracycline, Meloxicam', $case->condition);
        $this->assertSame('low', $case->severity);
        $this->assertSame(2, Treatment::where('health_case_id', $case->id)->count());
    }

    public function test_a_medicine_from_the_store_is_given_by_its_quantity(): void
    {
        $vet = $this->user('vet');
        $this->actingAs($vet)->post('/health/medicines', ['code' => 'LOX', 'name' => 'Loxin Injection 5%', 'category' => 'anti_inflammatory', 'unit' => 'ml', 'opening_qty' => 100])->assertSessionHasNoErrors();
        $med = \App\Models\Medicine::where('code', 'LOX')->sole();
        $this->actingAs($vet)->post('/health/medications', $this->body(['items' => [['medicine_id' => $med->id, 'qty_used' => 3, 'dose' => '3 CC', 'route' => 'injectable', 'frequency' => 'once']]]))->assertSessionHasNoErrors();
        $t = Treatment::where('medicine_id', $med->id)->sole();
        $this->assertSame('Loxin Injection 5%', $t->product);
        $this->assertSame(97.0, \App\Support\MedicineStock::quantities()[$med->id]);
        // a store medicine needs its quantity; a typed one needs its name
        $this->actingAs($vet)->post('/health/medications', $this->body(['items' => [['medicine_id' => $med->id, 'dose' => '3 CC', 'route' => 'injectable']]]))->assertSessionHasErrors('items.0.qty_used');
        $this->actingAs($vet)->post('/health/medications', $this->body(['items' => [['dose' => '3 CC', 'route' => 'injectable']]]))->assertSessionHasErrors('items.0.product');
    }

    public function test_a_recorded_dose_is_corrected_and_the_store_follows(): void // D-378
    {
        $vet = $this->user('vet');
        $this->actingAs($vet)->post('/health/medicines', ['code' => 'LOX', 'name' => 'Loxin Injection 5%', 'category' => 'anti_inflammatory', 'unit' => 'ml', 'opening_qty' => 100])->assertSessionHasNoErrors();
        $med = \App\Models\Medicine::where('code', 'LOX')->sole();
        $at = \App\Support\Feed::now()->subHour();
        $this->actingAs($vet)->post('/health/medications', $this->body(['given_on' => $at->toDateString(), 'given_time' => $at->format('H:i'), 'items' => [['medicine_id' => $med->id, 'qty_used' => 3, 'dose' => '3 CC', 'route' => 'injectable', 'frequency' => 'once']]]))->assertSessionHasNoErrors();
        $dose = \App\Models\TreatmentDose::sole();
        $this->assertSame($at->format('H:i'), $dose->done_at->setTimezone($at->getTimezone())->format('H:i')); // the dose time, not the entry time

        $this->actingAs($this->user('caretaker'))->post("/health/doses/{$dose->id}/edit", ['qty' => 5, 'done_on' => $at->toDateString(), 'done_time' => $at->format('H:i')])->assertForbidden();
        $this->actingAs($vet)->post("/health/doses/{$dose->id}/edit", ['qty' => 5, 'done_on' => $at->toDateString(), 'done_time' => $at->format('H:i'), 'reason' => 'typed 3 by mistake'])->assertSessionHasNoErrors();
        $this->assertSame(95.0, \App\Support\MedicineStock::quantities()[$med->id]);
        $this->actingAs($vet)->post("/health/doses/{$dose->id}/edit", ['qty' => 2, 'done_on' => $at->toDateString(), 'done_time' => $at->copy()->subMinutes(10)->format('H:i')])->assertSessionHasNoErrors();
        $this->assertSame(98.0, \App\Support\MedicineStock::quantities()[$med->id]);
        $this->assertSame(2.0, (float) $dose->fresh()->qty);
        $this->assertSame(2.0, (float) $dose->treatment->fresh()->qty_used);
        $this->assertSame(2, \App\Models\RecordRevision::where('record_type', 'dose')->where('record_id', $dose->id)->count());
    }
}
