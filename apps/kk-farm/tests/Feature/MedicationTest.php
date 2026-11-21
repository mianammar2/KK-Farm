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
        $missing = $this->body();
        unset($missing['items'][0]['meat_clear_on'], $missing['items'][0]['milk_clear_on']);
        $this->actingAs($vet)->post('/health/medications', $missing)->assertSessionHasErrors('items.0.meat_clear_on');
        $this->actingAs($vet)->post('/health/medications', $this->body(['items' => []]))->assertSessionHasErrors('items');
        $this->actingAs($vet)->post('/health/medications', $this->body(['given_on' => \App\Support\Feed::now()->addHours(2)->toDateString(), 'given_time' => \App\Support\Feed::now()->addHours(2)->format('H:i')]))->assertSessionHasErrors();
        $this->assertSame(4, Treatment::count());
        $this->assertSame(1, HealthCase::count());
    }

    public function test_a_medicine_keeps_its_standard_dose_and_a_treatment_keeps_its_dose_time(): void // D-369
    {
        $vet = $this->user('vet');
        $this->actingAs($vet)->post('/health/medicines', ['code' => 'OXY', 'name' => 'Oxytetracycline LA', 'category' => 'antibiotic', 'unit' => 'CC', 'opening_qty' => 100,
            'dose_qty' => 5, 'dose_route' => 'injectable', 'dose_frequency' => 'q72h', 'dose_days' => 6, 'meat_withdrawal_days' => 28, 'milk_withdrawal_days' => 7])->assertSessionHasNoErrors();
        $this->actingAs($vet)->get('/health')->assertInertia(fn ($p) => $p->where('medicines.0.dose', ['qty' => 5, 'route' => 'injectable', 'frequency' => 'q72h', 'days' => 6, 'meatDays' => 28, 'milkDays' => 7])->etc());

        $this->actingAs($vet)->post('/health/medications', $this->body())->assertSessionHasNoErrors();
        $case = HealthCase::where('animal_id', $this->goat->id)->sole();
        $at = \App\Support\Feed::now()->subMinutes(30);
        $this->actingAs($vet)->post("/health/cases/{$case->id}/treatments", ['given_on' => $at->toDateString(), 'given_time' => $at->format('H:i'), 'product' => 'Vitamin B', 'dose' => '2 ml', 'route' => 'injectable', 'no_withdrawal' => true])->assertSessionHasNoErrors();
        $this->assertSame($at->format('H:i'), Treatment::where('product', 'Vitamin B')->sole()->given_at->setTimezone($at->getTimezone())->format('H:i'));
    }

    public function test_without_a_reason_it_goes_under_a_case_named_after_the_medicines(): void // D-370
    {
        $this->actingAs($this->user('vet'))->post('/health/medications', $this->body(['reason' => null, 'severity' => null]))->assertSessionHasNoErrors();
        $case = HealthCase::where('animal_id', $this->goat->id)->sole();
        $this->assertSame('Medication: Oxytetracycline, Meloxicam', $case->condition);
        $this->assertSame('low', $case->severity);
        $this->assertSame(2, Treatment::where('health_case_id', $case->id)->count());
    }
}
