<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\FeedingGroup;
use App\Models\Lactation;
use App\Models\Mating;
use App\Models\MilkRecord;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Support\Feed;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** D-117: kidding makes a milking doe; milk per session; withdrawal discard; high yield; drying off. */
class MilkingTest extends TestCase
{
    use RefreshDatabase;

    private Animal $doe;

    private Breed $breed;

    private Pen $pen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-11-10 07:00:00', 'UTC'));
        Tenant::set(Organization::create(['name' => 'Milk Farm', 'slug' => 'milk-farm'])->id);
        $this->breed = Breed::create(['name' => 'Beetal', 'code' => 'BT', 'default_gestation_days' => 150]);
        $shed = Shed::create(['code' => 'S1', 'name' => 'KKF-LTU-01']);
        $this->pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->doe = Animal::create(['farm_number' => 'BT-D1', 'sex' => 'female', 'breed_id' => $this->breed->id, 'birth_date' => '2024-01-01', 'origin' => 'purchased', 'pen_id' => $this->pen->id, 'repro_status' => 'pregnant']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@milk.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function kid(string $on = '2026-11-05'): void
    {
        $m = Mating::create(['dam_id' => $this->doe->id, 'mated_on' => '2026-06-08', 'expected_kidding_on' => '2026-11-05', 'status' => 'pregnant', 'method' => 'natural', 'recorded_by' => 'x']);
        $this->actingAs($this->user('vet'))->post("/breeding/matings/{$m->id}/kidding", ['alive' => 2, 'stillborn' => 0, 'females' => 1, 'kidded_on' => $on])->assertSessionHasNoErrors();
        Tenant::flush();
    }

    private function milk(string $on, string $session, float $litres, ?string $id = null, string $role = 'caretaker')
    {
        return $this->actingAs($this->user($role))->post('/milking/records', ['milked_on' => $on, 'session' => $session, 'rows' => [['id' => $id ?? (string) Str::uuid(), 'animal_id' => $this->doe->id, 'litres' => $litres]]]);
    }

    public function test_kidding_makes_her_a_milking_doe_with_the_lactating_group_and_standard(): void
    {
        $this->assertSame('early_pregnant', Feed::nutritionCategory($this->doe));
        $this->kid();
        $doe = $this->doe->fresh();
        $l = Lactation::where('animal_id', $doe->id)->sole();
        $this->assertSame([1, '2026-11-05', 'kidding', null], [$l->number, $l->started_on->toDateString(), $l->source, $l->dried_on]);
        $this->assertSame('lactating', $doe->repro_status);
        $this->assertSame('lactating_does', Feed::groupCode($doe));
        $this->assertSame('Lactating Female', Feed::standardFor($doe, 40)->name);
        $this->assertTrue(\App\Models\AnimalEvent::where('animal_id', $doe->id)->where('summary', 'like', 'In milk: lactation 1 from 05/11/2026%')->exists());
    }

    public function test_milk_is_recorded_per_session_once_and_a_correction_supersedes_it(): void
    {
        $this->kid();
        $id = (string) Str::uuid();
        $this->milk('2026-11-09', 'morning', 1.2, $id)->assertSessionHasNoErrors();
        $this->milk('2026-11-09', 'morning', 1.2, $id)->assertSessionHasNoErrors(); // sent twice (offline retry)
        $this->milk('2026-11-09', 'evening', 0.8)->assertSessionHasNoErrors();
        $this->assertSame(2, MilkRecord::count());
        $this->milk('2026-11-09', 'morning', 1.5)->assertSessionHasNoErrors(); // correction
        $this->assertSame(3, MilkRecord::count(), 'nothing is deleted');
        $this->assertEqualsWithDelta(2.3, MilkRecord::current()->sum('litres'), 0.001);
        $this->milk('2026-11-01', 'morning', 1.0)->assertSessionHasErrors('rows.0.litres'); // before she kidded

        $this->actingAs($this->user('ceo'))->get('/milking')->assertInertia(fn ($page) => $page->component('Milking')->where('stats.inMilk', 1)
            ->where('does.0.number', 'BT-D1')->where('does.0.dim', 5)->where('does.0.total', 2.3)->where('does.0.avg7', 2.3));
        $this->actingAs($this->user('feeding'))->get('/milking')->assertForbidden();
    }

    public function test_milk_under_a_withdrawal_is_recorded_as_discarded(): void
    {
        $this->kid();
        $case = \App\Models\HealthCase::create(['case_no' => 1, 'animal_id' => $this->doe->id, 'onset_on' => '2026-11-07', 'condition' => 'Mastitis', 'severity' => 'moderate', 'status' => 'open', 'recorded_by' => 'vet']);
        DB::table('treatments')->insert(['org_id' => Tenant::current(), 'health_case_id' => $case->id, 'animal_id' => $this->doe->id, 'given_on' => '2026-11-07', 'product' => 'Oxytetracycline', 'dose' => '5 ml', 'route' => 'IM',
            'milk_clear_on' => '2026-11-12', 'recorded_by' => 'vet', 'created_at' => now(), 'updated_at' => now()]);
        $this->milk('2026-11-09', 'morning', 1.4)->assertSessionHasNoErrors();
        $r = MilkRecord::sole();
        $this->assertTrue($r->discarded);
        $this->assertSame('Milk withdrawal until 12/11/2026', $r->discard_reason);
        $this->actingAs($this->user('ceo'))->get('/milking')->assertInertia(fn ($page) => $page->where('does.0.withdrawal', '12/11/2026'));
    }

    public function test_a_high_producing_doe_moves_to_the_high_standard_and_drying_off_ends_the_lactation(): void
    {
        $this->kid();
        foreach (['2026-11-08' => 1.6, '2026-11-09' => 1.8] as $on => $l) {
            $this->milk($on, 'morning', $l)->assertSessionHasNoErrors();
            $this->milk($on, 'evening', 1.0)->assertSessionHasNoErrors();
        }
        // 7-day average: (2.6 + 2.8) ÷ 2 days = 2.7 L a day.
        $this->actingAs($this->user('manager'))->post('/milking/settings', ['high_yield_litres' => 2.5, 'dry_off_days' => 200])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertSame('High-Producing Lactating Female', Feed::standardFor($this->doe->fresh(), 40)->name);

        $l = Lactation::sole();
        $this->actingAs($this->user('caretaker'))->post("/milking/lactations/{$l->id}/dry-off", ['dried_on' => '2026-11-10', 'reason' => 'end'])->assertForbidden();
        $this->actingAs($this->user('manager'))->post("/milking/lactations/{$l->id}/dry-off", ['dried_on' => '2026-11-10', 'reason' => 'low_yield'])->assertSessionHasNoErrors();
        Tenant::flush();
        $doe = $this->doe->fresh();
        $this->assertSame(['dry', 'Low milk yield'], [$doe->repro_status, $l->fresh()->dry_reason]);
        $this->assertSame('Adult Breeding Female', Feed::standardFor($doe, 40)->name);
        $this->assertTrue(\App\Models\AnimalEvent::where('animal_id', $doe->id)->where('summary', 'like', 'Dried off: lactation 1 ended after 5 days, 5.4 L in total%')->exists());

        // Next kidding: lactation 2.
        $this->doe->update(['repro_status' => 'pregnant']);
        Mating::create(['dam_id' => $this->doe->id, 'mated_on' => '2026-06-12', 'expected_kidding_on' => '2026-11-09', 'status' => 'pregnant', 'method' => 'natural', 'recorded_by' => 'x']);
        $m = Mating::where('status', 'pregnant')->sole();
        $this->actingAs($this->user('manager'))->post("/milking/lactations/{$l->id}/dry-off", ['dried_on' => '2026-11-10', 'reason' => 'end'])->assertStatus(422);
        $this->actingAs($this->user('vet'))->post("/breeding/matings/{$m->id}/kidding", ['alive' => 1, 'stillborn' => 0, 'females' => 0, 'kidded_on' => '2026-11-10'])->assertSessionHasNoErrors();
        $this->assertSame(2, Lactation::open()->sole()->number);
    }

    public function test_a_doe_bought_in_milk_starts_her_lactation_on_arrival(): void
    {
        $this->actingAs($this->user('manager'))->post('/goats', ['origin' => 'purchased', 'breed_id' => $this->breed->id, 'acquired_on' => '2026-11-10', 'sex' => 'female', 'pen_id' => $this->pen->id,
            'milking_at_arrival' => 'yes', 'days_in_milk' => 20])->assertSessionHasNoErrors();
        $doe = Animal::where('farm_number', '!=', 'BT-D1')->sole();
        $l = Lactation::where('animal_id', $doe->id)->sole();
        $this->assertSame(['arrival', '2026-10-21'], [$l->source, $l->started_on->toDateString()]);
        $this->assertSame('lactating', $doe->fresh()->repro_status);

        $this->actingAs($this->user('manager'))->post('/goats', ['origin' => 'purchased', 'breed_id' => $this->breed->id, 'acquired_on' => '2026-11-10', 'sex' => 'female', 'pen_id' => $this->pen->id,
            'milking_at_arrival' => 'yes'])->assertSessionHasErrors('days_in_milk');
    }
}
