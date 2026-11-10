<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\FeedItem;
use App\Models\FeedStockMovement;
use App\Models\FeedUsage;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\TmrFormula;
use App\Models\User;
use App\Support\Feed;
use App\Support\Tenant;
use App\Support\Tmr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-177: TMR formulas, mixing batches and feed offered / refused / wasted. */
class TmrTest extends TestCase
{
    use RefreshDatabase;

    private Pen $pen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-11-10 02:30:00', 'UTC')); // 07:30 in Pakistan: the morning meal has started
        Tenant::set(Organization::create(['name' => 'TMR Farm', 'slug' => 'tmr-farm'])->id);
        $breed = Breed::create(['name' => 'Beetal']);
        $this->pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        Animal::create(['farm_number' => 'D-1', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $this->pen->id, 'animal_class' => 'breeding_female']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@tmr.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function feedItem(string $code, float $cp, float $cost, float $stock): FeedItem
    {
        $item = FeedItem::create(['code' => $code, 'name' => "Feed $code", 'category' => 'concentrate', 'cp_pct' => $cp, 'dm_pct' => 90, 'cost_per_kg_minor' => (int) ($cost * 100)]);
        FeedStockMovement::create(['feed_item_id' => $item->id, 'moved_on' => now()->toDateString(), 'type' => 'opening', 'qty_kg' => $stock, 'rate_minor' => $item->cost_per_kg_minor, 'recorded_by' => 'x']);

        return $item;
    }

    public function test_a_formula_is_analysed_and_a_batch_moves_ingredients_into_the_tmr(): void
    {
        $maize = $this->feedItem('MZ', 9, 80, 100);
        $soy = $this->feedItem('SB', 44, 200, 30);
        $vet = $this->user('vet');

        // Shares must add up to 100 %, and a TMR cannot be an ingredient.
        $this->actingAs($vet)->post('/feeding/tmr', ['name' => 'Grower TMR', 'code' => 'tmr-gr', 'lines' => [['feed_item_id' => $maize->id, 'pct' => 70], ['feed_item_id' => $soy->id, 'pct' => 20]]])->assertSessionHasErrors('lines');
        $this->actingAs($vet)->post('/feeding/tmr', ['name' => 'Grower TMR', 'code' => 'tmr-gr', 'lines' => [['feed_item_id' => $maize->id, 'pct' => 80], ['feed_item_id' => $soy->id, 'pct' => 20]]])->assertSessionHasNoErrors();
        $formula = TmrFormula::with('item')->firstOrFail();
        $this->assertSame('TMR-GR', $formula->item->code);
        $this->assertSame('tmr', $formula->item->category);
        $this->assertEqualsWithDelta(16.0, (float) $formula->item->cp_pct, 0.01);            // 0.8 × 9 + 0.2 × 44
        $this->assertSame(10400, $formula->item->cost_per_kg_minor);                          // 0.8 × 80 + 0.2 × 200 = PKR 104
        $this->actingAs($vet)->post('/feeding/tmr', ['name' => 'Bad', 'code' => 'BAD', 'lines' => [['feed_item_id' => $formula->feed_item_id, 'pct' => 50], ['feed_item_id' => $maize->id, 'pct' => 50]]])->assertSessionHasErrors('lines.0.feed_item_id');

        // A 200 KG batch would need 40 KG of soy but only 30 KG is in store.
        $feeder = $this->user('feeding');
        $this->actingAs($feeder)->post("/feeding/tmr/{$formula->id}/mix", ['total_kg' => 200, 'mixed_on' => now()->toDateString()])->assertSessionHasErrors('total_kg');
        $this->assertSame(0, FeedStockMovement::whereNotNull('tmr_batch_id')->count());

        $this->actingAs($feeder)->post("/feeding/tmr/{$formula->id}/mix", ['total_kg' => 100, 'mixed_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $stock = Feed::stock(true);
        $this->assertEqualsWithDelta(20.0, $stock[$maize->id], 0.01);   // 100 − 80
        $this->assertEqualsWithDelta(10.0, $stock[$soy->id], 0.01);     // 30 − 20
        $this->assertEqualsWithDelta(100.0, $stock[$formula->feed_item_id], 0.01);
        $this->assertSame(10400, $formula->item->fresh()->cost_per_kg_minor);

        // Mixing types are only written by a batch, never by hand on the stock form.
        $this->actingAs($this->user('manager'))->post('/feeding/stock', ['feed_item_id' => $maize->id, 'type' => 'mix_in', 'qty_kg' => 5, 'moved_on' => now()->toDateString()])->assertSessionHasErrors('type');
        $this->actingAs($vet)->get('/feeding?tab=tmr')->assertInertia(fn (AssertableInertia $p) => $p->where('tmr.formulas.0.cp', 16)->where('tmr.batches.0.kg', 100));
    }

    public function test_wasted_feed_is_not_counted_as_eaten_and_shows_in_the_report(): void
    {
        $hay = $this->feedItem('HAY', 8, 30, 50);
        $feeder = $this->user('feeding');
        $this->actingAs($feeder)->post('/feeding/record', ['pen_id' => $this->pen->id, 'meal' => 'morning', 'lines' => [['id' => (string) Str::uuid(), 'feed_item_id' => $hay->id, 'offered_kg' => 10]]])->assertSessionHasNoErrors();
        $usage = FeedUsage::current()->firstOrFail();

        $this->actingAs($feeder)->post('/feeding/leftover', ['lines' => [['id' => (string) Str::uuid(), 'usage_id' => $usage->id, 'refused_kg' => 6, 'wasted_kg' => 5]]])->assertSessionHasErrors('lines.0.refused_kg');
        $this->actingAs($feeder)->post('/feeding/leftover', ['lines' => [['id' => (string) Str::uuid(), 'usage_id' => $usage->id, 'refused_kg' => 1.5, 'wasted_kg' => 0.5]]])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(8.0, FeedUsage::current()->firstOrFail()->consumedKg(), 0.01);

        $report = Tmr::wasteReport(7);
        $this->assertSame(['offered' => 10.0, 'refused' => 1.5, 'wasted' => 0.5, 'eaten' => 8.0, 'wastePct' => 20.0], $report['total']);
        $this->actingAs($feeder)->get('/feeding?tab=waste&days=30')->assertInertia(fn (AssertableInertia $p) => $p->where('waste.days', 30)->where('waste.rows.0.wasted', 0.5));
    }
}
