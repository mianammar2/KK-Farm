<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthCase;
use App\Models\HealthRecord;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** D-250: a goat leaves Watch when its later weighings and checkups show it has recovered. */
class WatchRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Animal $goat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Watch Farm', 'slug' => 'watch-farm'])->id);
        \App\Models\Department::seedDefaults((string) Tenant::current());
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->goat = Animal::create(['farm_number' => 'W-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Beetal'])->id, 'origin' => 'purchased', 'pen_id' => $pen->id, 'birth_date' => now()->subYears(3)])->refresh();
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@watch.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function checkup(int $daysAgo, array $more)
    {
        return $this->actingAs($this->user('vet'))->post('/health/checkups', $more + ['animal_id' => $this->goat->id, 'checked_on' => now()->subDays($daysAgo)->toDateString()]);
    }

    private function weigh(int $daysAgo, float $kg)
    {
        return $this->actingAs($this->user('caretaker'))->post('/weights', ['animal_id' => $this->goat->id, 'weight_kg' => $kg, 'measured_on' => now()->subDays($daysAgo)->toDateString()]);
    }

    private function flag(): string
    {
        return $this->goat->fresh()->health_flag;
    }

    /** The owner's example: 32.2 → 30.1 (alarm) → 29.5 (still watched) → 35 KG: out of Watch. */
    public function test_the_owners_goat_leaves_watch_when_its_weight_comes_back(): void
    {
        $this->weigh(23, 32.2)->assertSessionHasNoErrors();
        $this->checkup(19, ['weight_kg' => 30.1, 'temperature_f' => 102.5, 'outcome' => 'monitor'])->assertSessionHasNoErrors();
        $this->assertSame('monitor', $this->flag(), '6.5% down: on Watch');
        $this->assertTrue(Task::where('title', 'like', 'Vet check W-1:%')->whereIn('status', Task::OPEN)->exists(), 'the vet was alerted');
        $this->checkup(9, ['weight_kg' => 29.5, 'temperature_f' => 102.8, 'outcome' => 'monitor'])->assertSessionHasNoErrors();
        $this->assertSame('monitor', $this->flag(), 'still lower: still on Watch');
        $this->assertSame(2, HealthRecord::where('animal_id', $this->goat->id)->where('status', 'open')->count());

        $this->weigh(2, 35)->assertSessionHasNoErrors(); // +5 KG
        $this->assertSame('healthy', $this->flag(), 'recovered: out of Watch');
        $this->assertSame(0, HealthRecord::where('animal_id', $this->goat->id)->where('status', 'open')->count());
        $this->assertStringContainsString('Cleared automatically: weight back to 35 KG', HealthRecord::where('animal_id', $this->goat->id)->orderBy('id')->first()->notes);
        $this->assertFalse(Task::where('title', 'like', 'Vet check W-1:%')->whereIn('status', Task::OPEN)->exists(), "the vet's check is closed with the reason");
        $this->assertTrue(\App\Models\AnimalEvent::where('animal_id', $this->goat->id)->where('summary', 'like', 'Out of Watch:%')->exists());
    }

    public function test_a_weight_still_down_or_a_fever_keeps_the_goat_on_watch(): void
    {
        $this->weigh(20, 40)->assertSessionHasNoErrors();
        $this->checkup(15, ['weight_kg' => 37, 'outcome' => 'monitor'])->assertSessionHasNoErrors(); // 7.5% down
        $this->weigh(10, 38.5)->assertSessionHasNoErrors(); // better but still 3.75% under 40
        $this->assertSame('monitor', $this->flag());
        $this->weigh(5, 39)->assertSessionHasNoErrors(); // within 3% of 40
        $this->assertSame('healthy', $this->flag());

        // a fever: weight alone does not clear it, a normal temperature does
        $this->checkup(4, ['temperature_f' => 105, 'outcome' => 'monitor'])->assertSessionHasNoErrors();
        $this->weigh(3, 42)->assertSessionHasNoErrors();
        $this->assertSame('monitor', $this->flag(), 'temperature not yet checked again');
        $this->checkup(2, ['temperature_f' => 104.4, 'outcome' => 'monitor'])->assertSessionHasNoErrors();
        $this->assertSame('monitor', $this->flag(), 'still a fever');
        $this->checkup(1, ['temperature_f' => 102.6, 'outcome' => 'healthy'])->assertSessionHasNoErrors();
        $this->assertSame('healthy', $this->flag());
    }

    public function test_a_sick_goat_cases_and_peoples_reports_are_never_cleared_by_weight(): void
    {
        $this->weigh(20, 30)->assertSessionHasNoErrors();
        $this->checkup(15, ['outcome' => 'sick'])->assertSessionHasNoErrors();
        $this->weigh(10, 33)->assertSessionHasNoErrors();
        $this->assertSame('monitor', $this->flag(), 'a Sick result needs a Healthy checkup, not just weight');
        $this->checkup(5, ['outcome' => 'healthy'])->assertSessionHasNoErrors();
        $this->assertSame('healthy', $this->flag());

        // a caretaker's report and an open case stay with people
        HealthRecord::create(['animal_id' => $this->goat->id, 'kind' => 'observation', 'occurred_on' => now()->subDays(4), 'title' => 'Coughing', 'recorded_by' => 'Ali', 'recorded_role' => 'Caretaker', 'status' => 'open']);
        HealthCase::create(['case_no' => 1, 'animal_id' => $this->goat->id, 'onset_on' => now()->subDays(4), 'condition' => 'Pneumonia', 'severity' => 'low', 'status' => 'under_treatment', 'recorded_by' => 'vet']);
        \App\Support\Checkups::syncHealth($this->goat->fresh());
        $this->weigh(1, 36)->assertSessionHasNoErrors();
        $this->assertSame('sick', $this->flag());
        $this->assertTrue(HealthRecord::where('title', 'Coughing')->where('status', 'open')->exists());
    }

    public function test_the_daily_round_clears_goats_that_recovered_before_this_rule(): void
    {
        // a watch from before D-250, with the recovery weighing already on record
        WeightRecord::create(['animal_id' => $this->goat->id, 'measured_on' => now()->subDays(23), 'weight_kg' => 32.2, 'measured_by' => 'x']);
        WeightRecord::create(['animal_id' => $this->goat->id, 'measured_on' => now()->subDays(19), 'weight_kg' => 30.1, 'measured_by' => 'x']);
        $old = HealthRecord::create(['animal_id' => $this->goat->id, 'kind' => 'observation', 'occurred_on' => now()->subDays(19), 'title' => 'Checkup: Weight down 2.1 KG (6.5%) since 21 Oct', 'severity' => 'medium', 'recorded_by' => 'vet', 'recorded_role' => 'Veterinarian', 'status' => 'open']);
        \Illuminate\Support\Facades\DB::table('weight_records')->insert(['org_id' => Tenant::current(), 'animal_id' => $this->goat->id, 'measured_on' => now()->subDays(2), 'weight_kg' => 35, 'measured_by' => 'x', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        \App\Support\Checkups::syncHealth($this->goat->fresh());
        $this->assertSame('monitor', $this->flag());

        Tenant::leave();
        Artisan::call('kkfarm:watch-review');
        Tenant::set($this->goat->org_id);
        $this->assertSame('closed', $old->fresh()->status);
        $this->assertSame('healthy', $this->flag());
    }
}
