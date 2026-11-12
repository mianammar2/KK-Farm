<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthCase;
use App\Models\Lactation;
use App\Models\Mating;
use App\Models\MilkRecord;
use App\Models\Organization;
use App\Models\RfidTag;
use App\Models\StatusHistory;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Farm;
use App\Support\Lifecycle;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** D-232: independent statuses, status history and sale clearance (UC-07, UC-08, UC-10, UC-11, UC-15). */
class LifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_statuses_are_independent_and_market_weight_is_not_a_sale(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Life Farm', 'slug' => 'life-farm'])->id);
        $manager = User::create(['email' => 'm@life.test', 'name' => 'Manager', 'password' => 'x', 'role' => 'manager', 'org_id' => Tenant::current(), 'active' => true]);
        $breed = Breed::create(['name' => 'Teddy']);
        $i = 0;
        $goat = function (string $tag, string $sex, int $months, array $more = []) use ($breed, $manager, &$i) {
            $g = Animal::create($more + ['farm_number' => $tag, 'sex' => $sex, 'breed_id' => $breed->id, 'birth_date' => now()->subMonths($months), 'origin' => 'purchased', 'status' => 'active']);
            Farm::assignTag($g, RfidTag::create(['tag_code' => 'E2000'.(++$i), 'status' => 'in_stock']), 'initial', $manager);

            return $g->fresh();
        };
        $weigh = fn (Animal $g, float $kg, int $ago = 0) => WeightRecord::create(['animal_id' => $g->id, 'measured_on' => now()->subDays($ago)->toDateString(), 'weight_kg' => $kg, 'measured_by' => 'Test', 'source' => 'manual']);

        // UC-07: a fattening male reaching 25 KG: Market Weight Achieved, clearance pending, not sale ready.
        $fm = $goat('FM1', 'male', 7, ['animal_purpose' => 'fattening']);
        $weigh($fm, 22, 20);
        Tenant::flush();
        $this->assertSame(['in_programme', 'growing'], [Lifecycle::statuses($fm)['fattening']['code'], Lifecycle::statuses($fm)['sale']['code']]);
        $weigh($fm, 25.5);
        Tenant::flush();
        $this->assertSame(['market_weight', 'market_weight'], [Lifecycle::statuses($fm)['fattening']['code'], Lifecycle::statuses($fm)['sale']['code']]);
        $this->assertSame('MARKET-WEIGHT', StatusHistory::where('animal_id', $fm->id)->where('dimension', 'sale')->latest('id')->value('rule'), 'the weighing wrote the change');

        // UC-08: a breeding buck at 30 KG stays a breeding buck with no sale status.
        $bb = $goat('BB1', 'male', 30, ['animal_purpose' => 'breeding']);
        $weigh($bb, 30);
        Tenant::flush();
        $this->assertSame(['breeding_active', 'not_for_sale'], [Lifecycle::statuses($bb)['breeding']['code'], Lifecycle::statuses($bb)['sale']['code']]);

        // Clearance makes it sale ready; then sickness blocks it at once (UC-15) and clearing a sick goat is refused.
        $this->actingAs($manager)->post("/sales/readiness/{$fm->id}", ['decision' => 'cleared'])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertSame('sale_ready', Lifecycle::statuses($fm->fresh())['sale']['code']);
        HealthCase::create(['case_no' => 1, 'animal_id' => $fm->id, 'onset_on' => now(), 'condition' => 'Cough', 'severity' => 'low', 'status' => 'open', 'recorded_by' => 'x']);
        Tenant::flush();
        $sale = Lifecycle::statuses($fm->fresh())['sale'];
        $this->assertSame('blocked', $sale['code']);
        $this->assertStringContainsString('Open health case', implode(' ', $sale['blockers']));
        $sick = $goat('FM2', 'male', 8, ['animal_purpose' => 'fattening']);
        $weigh($sick, 26);
        HealthCase::create(['case_no' => 2, 'animal_id' => $sick->id, 'onset_on' => now(), 'condition' => 'Lame', 'severity' => 'low', 'status' => 'open', 'recorded_by' => 'x']);
        Tenant::flush();
        $this->assertSame('blocked', Lifecycle::statuses($sick->fresh())['sale']['code']);
        $this->actingAs($manager)->post("/sales/readiness/{$sick->id}", ['decision' => 'cleared'])->assertSessionHasErrors('decision');
        // Breeding stock needs a management reason.
        $this->actingAs($manager)->post("/sales/readiness/{$bb->id}", ['decision' => 'cleared'])->assertSessionHasErrors('reason');

        // UC-10: a doe pregnant and milking at once keeps both statuses; nothing conflicts.
        $doe = $goat('BD1', 'female', 30, ['animal_purpose' => 'breeding']);
        Mating::create(['dam_id' => $doe->id, 'mated_on' => now()->subDays(40)->toDateString(), 'expected_kidding_on' => now()->addDays(110)->toDateString(), 'status' => 'pregnant', 'method' => 'natural', 'recorded_by' => 'x']);
        $l = Lactation::create(['animal_id' => $doe->id, 'number' => 1, 'started_on' => now()->subDays(90), 'source' => 'kidding', 'recorded_by' => 'x']);
        MilkRecord::create(['id' => (string) Str::uuid(), 'animal_id' => $doe->id, 'lactation_id' => $l->id, 'milked_on' => now()->toDateString(), 'session' => 'morning', 'litres' => 2.0, 'discarded' => false, 'recorded_by' => 'x']);
        Tenant::flush();
        $s = Lifecycle::statuses($doe->fresh());
        $this->assertSame(['pregnant', 'milking', 'not_for_sale'], [$s['breeding']['code'], $s['lactation']['code'], $s['sale']['code']]);

        // UC-11: medicine with a milk withdrawal: milk hold on the lactation, veterinary hold on health.
        $case = HealthCase::create(['case_no' => 3, 'animal_id' => $doe->id, 'onset_on' => now(), 'condition' => 'Mastitis', 'severity' => 'low', 'status' => 'open', 'recorded_by' => 'x']);
        Treatment::create(['health_case_id' => $case->id, 'animal_id' => $doe->id, 'given_on' => now(), 'product' => 'Oxy', 'dose' => '5 ml', 'route' => 'injectable', 'cost_minor' => 0, 'milk_clear_on' => now()->addDays(5), 'meat_clear_on' => now()->addDays(10), 'recorded_by' => 'x']);
        Tenant::flush();
        $s = Lifecycle::statuses($doe->fresh());
        $this->assertSame('vet_hold', $s['health']['code']);
        $this->assertStringContainsString('Milk hold', (string) $s['lactation']['note']);

        // The history keeps every change with its rule; the goat page and the readiness page show them.
        $this->assertGreaterThan(0, Lifecycle::record($doe->fresh()));
        $this->assertSame(0, Lifecycle::record($doe->fresh()), 'nothing changed, nothing written');
        $this->actingAs($manager)->get("/goats/{$doe->id}")->assertInertia(fn ($p) => $p->where('statuses.breeding.code', 'pregnant')->where('statuses.lactation.code', 'milking'));
        $this->actingAs($manager)->get('/sales/readiness')->assertOk()->assertInertia(fn ($p) => $p->component('Sales/Readiness')->where('rows', fn ($r) => collect($r)->firstWhere('number', 'FM2')['sale'] === 'blocked'));
        $this->artisan('kkfarm:status-round')->assertSuccessful();
    }
}
