<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthCase;
use App\Models\HealthRecord;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Treatment;
use App\Models\User;
use App\Support\Tenant;
use App\Support\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-073: health cases, treatments and the withdrawal lock (workbook "Health Cases" and "Treatments"). */
class HealthCaseTest extends TestCase
{
    use RefreshDatabase;

    private Animal $goat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Vet Farm', 'slug' => 'vet-farm'])->id);
        $breed = Breed::create(['name' => 'Beetal', 'default_gestation_days' => 150]);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->goat = Animal::create(['farm_number' => 'G-1', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $pen->id]);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@vet.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function openCase(array $extra = []): HealthCase
    {
        $this->actingAs($this->user('vet'))->post('/health/cases', $extra + ['animal_id' => $this->goat->id, 'onset_on' => now()->toDateString(), 'condition' => 'Pneumonia', 'severity' => 'high'])->assertSessionHasNoErrors();

        return HealthCase::latest('id')->firstOrFail();
    }

    private function treat(HealthCase $case, array $extra = [])
    {
        return $this->actingAs($this->user('vet'))->post("/health/cases/{$case->id}/treatments", $extra + ['given_on' => now()->toDateString(), 'product' => 'Oxytetracycline', 'dose' => '5 ml', 'route' => 'injectable']);
    }

    public function test_only_a_veterinarian_opens_cases_and_treats(): void
    {
        $this->actingAs($this->user('caretaker'))->post('/health/cases', ['animal_id' => $this->goat->id, 'onset_on' => now()->toDateString(), 'condition' => 'X', 'severity' => 'low'])->assertForbidden();
        $this->actingAs($this->user('manager'))->post('/health/cases', ['animal_id' => $this->goat->id, 'onset_on' => now()->toDateString(), 'condition' => 'X', 'severity' => 'low'])->assertForbidden();
        $case = $this->openCase();
        $this->actingAs($this->user('caretaker'))->post("/health/cases/{$case->id}/treatments", ['given_on' => now()->toDateString(), 'product' => 'X', 'dose' => '1', 'route' => 'oral', 'no_withdrawal' => true])->assertForbidden();

        $this->assertSame('HC-0001', $case->label());
        $this->assertSame('HC-0002', $this->openCase(['condition' => 'Lameness', 'severity' => 'low'])->label());
        $this->assertSame('sick', $this->goat->fresh()->health_flag);
    }

    public function test_a_treatment_needs_the_vets_clear_dates_or_an_explicit_no_withdrawal(): void
    {
        $case = $this->openCase();

        $this->treat($case)->assertSessionHasErrors('meat_clear_on');
        $this->treat($case, ['meat_clear_on' => now()->subDay()->toDateString()])->assertSessionHasErrors('meat_clear_on'); // before the dose
        $this->treat($case, ['no_withdrawal' => true])->assertSessionHasNoErrors();
        $this->treat($case, ['meat_clear_on' => now()->addDays(28)->toDateString(), 'milk_clear_on' => now()->addDays(7)->toDateString()])->assertSessionHasNoErrors();

        $this->assertSame(2, Treatment::count());
        $this->assertSame('under_treatment', $case->fresh()->status);
        $w = Withdrawal::forAnimal($this->goat->id);
        $this->assertSame([now()->addDays(28)->toDateString(), now()->addDays(7)->toDateString()], [$w['meat']->toDateString(), $w['milk']->toDateString()]);
    }

    public function test_a_goat_under_meat_withdrawal_cannot_leave_for_meat_until_the_clear_date(): void
    {
        $case = $this->openCase();
        $this->treat($case, ['meat_clear_on' => now()->addDays(10)->toDateString()])->assertSessionHasNoErrors();
        $manager = $this->user('manager');

        $this->actingAs($manager)->post("/goats/{$this->goat->id}/exit", ['reason' => 'sold'])->assertSessionHasErrors('reason');
        $this->actingAs($manager)->post("/goats/{$this->goat->id}/exit", ['reason' => 'sacrificed'])->assertSessionHasErrors('reason');
        $this->assertSame('active', $this->goat->fresh()->status);
        $this->actingAs($manager)->get("/goats/{$this->goat->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('goat.withdrawal.meat', now()->addDays(10)->format('d/m/Y')));

        $this->travel(11)->days();
        $this->assertNull(Withdrawal::forAnimal($this->goat->id)['meat']);
        $this->actingAs($manager)->post("/goats/{$this->goat->id}/exit", ['reason' => 'sold'])->assertSessionHasNoErrors();
        $this->assertSame('left_farm', $this->goat->fresh()->status);
    }

    public function test_a_death_can_always_be_recorded(): void
    {
        $case = $this->openCase();
        $this->treat($case, ['meat_clear_on' => now()->addDays(10)->toDateString()]);
        // D-260: the vet reports the death, the administrator approves it; a withdrawal never blocks it
        $this->actingAs($this->user('vet'))->post("/goats/{$this->goat->id}/death", ['died_at' => now()->subHour()->format('Y-m-d H:i'), 'cause' => 'Pneumonia', 'circumstances' => 'Found dead in the pen'])->assertSessionHasNoErrors();
        $this->actingAs($this->user('ceo'))->post('/death-reports/'.\App\Models\DeathReport::first()->id, ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('deceased', $this->goat->fresh()->status);
    }

    public function test_the_goats_health_follows_its_cases(): void
    {
        $case = $this->openCase(['isolated' => true]);
        $this->assertSame('quarantine', $this->goat->fresh()->health_flag);

        $this->actingAs($this->user('vet'))->post("/health/cases/{$case->id}", ['status' => 'monitoring', 'isolated' => false])->assertSessionHasNoErrors();
        $this->assertSame('recovering', $this->goat->fresh()->health_flag);

        $this->actingAs($this->user('vet'))->post("/health/cases/{$case->id}", ['status' => 'recovered', 'diagnosis' => 'Bacterial'])->assertSessionHasNoErrors();
        $this->assertSame(['healthy', now()->toDateString()], [$this->goat->fresh()->health_flag, $case->fresh()->resolved_on->toDateString()]);
        $this->treat($case->fresh(), ['no_withdrawal' => true])->assertStatus(422);
    }

    public function test_a_reported_concern_becomes_a_case_and_leaves_the_reports_list(): void
    {
        $this->actingAs($this->user('vet'))->post('/health/observations', ['animal_id' => $this->goat->id, 'title' => 'Coughing', 'severity' => 'medium'])->assertSessionHasNoErrors(); // D-252
        $report = HealthRecord::sole();
        $this->assertSame('monitor', $this->goat->fresh()->health_flag);

        $this->openCase(['report_id' => $report->id, 'condition' => 'Coughing']);
        $this->assertSame('closed', $report->fresh()->status);
        $this->actingAs($this->user('vet'))->get('/health')->assertInertia(fn (AssertableInertia $p) => $p->has('reports', 0)->has('cases', 1)->where('cases.0.label', 'HC-0001'));
    }
}
