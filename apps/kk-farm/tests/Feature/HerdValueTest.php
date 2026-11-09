<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\AnimalEvent;
use App\Models\Breed;
use App\Models\MarketRate;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** D-113: Herd Value: live weight × market rate against purchase and feed, per day, week and month. */
class HerdValueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Animal $goat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-11-10 07:00:00', 'UTC')); // noon in Pakistan
        $this->org = Organization::create(['name' => 'Value Farm', 'slug' => 'value-farm']);
        Tenant::set($this->org->id);
        $breed = Breed::create(['name' => 'Makhi Cheeni', 'code' => 'MC', 'default_gestation_days' => 150]);
        $shed = Shed::create(['code' => 'S1', 'name' => 'KKF-BDU-01']);
        $pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P08', 'purpose' => 'Does']);
        // An adult (2.5% of body weight a day by default), bought 1 Nov at 20 KG, 22 KG from 8 Nov.
        $this->goat = Animal::create(['farm_number' => 'MC011126-01', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => '2024-06-01', 'birth_date_is_estimate' => true,
            'origin' => 'purchased', 'acquired_on' => '2026-11-01', 'pen_id' => $pen->id, 'animal_purpose' => 'fattening']);
        WeightRecord::create(['animal_id' => $this->goat->id, 'measured_on' => '2026-11-01', 'weight_kg' => 20, 'measured_by' => 'x']);
        WeightRecord::create(['animal_id' => $this->goat->id, 'measured_on' => '2026-11-08', 'weight_kg' => 22, 'measured_by' => 'x']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@value.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_the_herd_is_valued_by_live_weight_against_purchase_and_feed(): void
    {
        $admin = $this->actingAs($this->user('ceo'));
        $admin->post('/value/rates', ['rate_on' => '2026-11-01', 'rate' => 1000])->assertSessionHasNoErrors();
        $admin->post('/value/rates', ['rate_on' => '2026-11-09', 'rate' => 1200])->assertSessionHasNoErrors();
        $admin->post('/value/rates', ['rate_on' => '2026-11-09', 'purpose' => 'fattening', 'rate' => 1500])->assertSessionHasNoErrors();
        $admin->post('/value/batch', ['batch' => 'MC011126', 'rate' => 900])->assertSessionHasNoErrors();
        $admin->post('/value/feed-price', ['price' => 100])->assertSessionHasNoErrors();
        $this->assertSame(90000, $this->goat->fresh()->purchase_rate_minor_per_kg);
        $this->assertTrue(AnimalEvent::where('animal_id', $this->goat->id)->where('summary', 'like', '%Purchase Rate: empty → 900 PKR/KG%')->exists());

        // Value 22 KG × 1,500 (its Qurbani rate) = 33,000. Bought 20 KG × 900 = 18,000.
        // Feed 1–10 Nov at 2.5% × 100 PKR/KG: 7 days at 20 KG (50/day) + 3 days at 22 KG (55/day) = 515.
        // Last 7 days: from 3 Nov, 20 KG × 1,000 = 20,000 → 33,000 is +13,000; feed 5 × 50 + 3 × 55 = 415; net 12,585.
        $this->actingAs($this->user('ceo'))->get('/value')->assertInertia(fn ($page) => $page->component('HerdValue')
            ->where('rate.now', 1200)->where('rate.weekAgo', 1000)->where('rate.byPurpose.fattening', 1500)
            ->where('total.value', 33000)->where('total.buy', 18000)->where('total.feed', 515)->where('total.gain', 14485)->where('total.costPerKg', 842)
            ->where('total.feedDay', 55)->where('total.periods.week.value', 13000)->where('total.periods.week.feed', 415)->where('total.periods.week.net', 12585)
            ->where('total.periods.week.kg', 2)->where('batches.0.batch', 'MC011126')->where('batches.0.buyRate', 900)
            ->where('purposes.0.label', 'Fattening / Qurbani')->where('goats.0.gain', 14485));
    }

    public function test_without_a_purchase_rate_there_is_no_gain_and_only_the_administrator_enters_prices(): void
    {
        $this->actingAs($this->user('ceo'))->post('/value/rates', ['rate_on' => '2026-11-10', 'rate' => 1000])->assertSessionHasNoErrors();
        $this->actingAs($this->user('ceo'))->get('/value')->assertInertia(fn ($page) => $page->where('total.value', 22000)->where('total.gain', null));

        $this->actingAs($this->user('manager'))->get('/value')->assertOk();
        $this->actingAs($this->user('manager'))->post('/value/rates', ['rate_on' => '2026-11-10', 'rate' => 5])->assertForbidden();
        $this->actingAs($this->user('vet'))->get('/value')->assertForbidden();
        $this->actingAs($this->user('ceo'))->post('/value/rates', ['rate_on' => '2026-12-01', 'rate' => 1000])->assertSessionHasErrors('rate_on');
        $this->assertSame(1, MarketRate::count(), 'rates are only added, never changed');
    }

    public function test_another_farm_never_sees_these_rates(): void
    {
        MarketRate::create(['rate_on' => '2026-11-10', 'rate_minor_per_kg' => 100000, 'recorded_by' => 'x']);
        $other = Organization::create(['name' => 'Other Farm', 'slug' => 'other-farm']);
        Tenant::enter($other->id);
        $this->assertSame(0, MarketRate::count());
        Tenant::enter($this->org->id);
        $this->assertSame(1, MarketRate::count());
    }

    /** D-114: only Qurbani, sale and meat goats are priced; a batch's purpose brings it in. */
    public function test_breeding_goats_and_goats_without_a_purpose_are_not_priced(): void
    {
        $breed = $this->goat->breed_id;
        Animal::create(['farm_number' => 'TD141026-01', 'sex' => 'female', 'breed_id' => $breed, 'origin' => 'purchased', 'acquired_on' => '2026-10-14', 'animal_purpose' => 'breeding', 'birth_date' => '2024-01-01']);
        $loose = Animal::create(['farm_number' => 'MC241026-01', 'sex' => 'female', 'breed_id' => $breed, 'origin' => 'purchased', 'acquired_on' => '2026-10-24', 'birth_date' => '2025-01-01']);
        WeightRecord::create(['animal_id' => $loose->id, 'measured_on' => '2026-10-24', 'weight_kg' => 30, 'measured_by' => 'x']);
        $this->actingAs($this->user('ceo'))->post('/value/rates', ['rate_on' => '2026-11-10', 'rate' => 1000])->assertSessionHasNoErrors();

        $this->actingAs($this->user('ceo'))->get('/value')->assertInertia(fn ($page) => $page->where('total.goats', 1)->where('total.value', 22000)
            ->where('excluded.breeding', 1)->where('excluded.none', 1)->has('allBatches', 3)->where('tradeOptions.meat', 'Meat Sale')->missing('tradeOptions.breeding'));

        $this->actingAs($this->user('ceo'))->post('/value/batch', ['batch' => 'MC241026', 'purpose' => 'meat'])->assertSessionHasNoErrors();
        $this->assertSame('meat', $loose->fresh()->animal_purpose);
        $this->actingAs($this->user('ceo'))->get('/value')->assertInertia(fn ($page) => $page->where('total.goats', 2)->where('total.value', 52000)->where('excluded.none', 0));
    }
}
