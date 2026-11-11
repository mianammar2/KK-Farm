<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\AnimalMovement;
use App\Models\Breed;
use App\Models\FeedItem;
use App\Models\FeedStockCount;
use App\Models\FeedStockMovement;
use App\Models\FeedUsage;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\PurchaseOrder;
use App\Models\Shed;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\TmrBatch;
use App\Models\TmrFormula;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Feed;
use App\Support\FeedCost;
use App\Support\Tenant;
use App\Support\Tmr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** D-192: V3 journey C Feed-to-True-Cost: cost split to goats in the pen, gain, cost/KG gain, FCR, shrinkage, PO from shortfall. */
class FeedTrueCostTest extends TestCase
{
    use RefreshDatabase;

    private Pen $a;

    private Pen $b;

    private FeedItem $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Cost Farm', 'slug' => 'cost-farm'])->id);
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $this->a = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Fattening']);
        $this->b = Pen::create(['shed_id' => $shed->id, 'name' => 'P02', 'purpose' => 'Fattening']);
        $this->feed = FeedItem::create(['code' => 'F1', 'name' => 'Concentrate', 'category' => 'concentrate', 'cost_per_kg_minor' => 10000, 'dm_pct' => 90]);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@cost.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function goat(string $tag, Pen $pen): Animal
    {
        return Animal::create(['farm_number' => $tag, 'sex' => 'male', 'breed_id' => Breed::firstOrCreate(['name' => 'Beetal'])->id, 'birth_date' => now()->subYear(), 'origin' => 'born_on_farm', 'pen_id' => $pen->id, 'status' => 'active']);
    }

    private function meal(Pen $pen, string $day, float $kg, int $head, float $refused = 0): void
    {
        FeedUsage::create(['id' => (string) Str::uuid(), 'fed_on' => $day, 'meal' => 'morning', 'pen_id' => $pen->id, 'feed_item_id' => $this->feed->id, 'headcount' => $head,
            'planned_kg' => $kg, 'offered_kg' => $kg, 'refused_kg' => $refused ?: null, 'unit_cost_minor' => 10000, 'fed_at' => now(), 'fed_by' => 'x']);
    }

    public function test_pen_cost_is_split_to_the_goats_present_and_turned_into_cost_per_kg_gained(): void
    {
        $from = now()->subDays(9)->startOfDay();
        $to = now()->startOfDay();
        $g1 = $this->goat('C-1', $this->a);
        $g2 = $this->goat('C-2', $this->a);
        foreach ([$g1, $g2] as $g) {
            WeightRecord::create(['animal_id' => $g->id, 'measured_on' => $from->copy()->subDay(), 'weight_kg' => 30, 'measured_by' => 'x']);
        }
        WeightRecord::create(['animal_id' => $g1->id, 'measured_on' => $to, 'weight_kg' => 32, 'measured_by' => 'x']);
        WeightRecord::create(['animal_id' => $g2->id, 'measured_on' => $to, 'weight_kg' => 31, 'measured_by' => 'x']);
        // C-2 moves to pen B after day 5: from then on pen A's meals are C-1's alone.
        AnimalMovement::create(['animal_id' => $g2->id, 'moved_on' => $from->copy()->addDays(5), 'type' => 'transfer', 'from_pen_id' => $this->a->id, 'to_pen_id' => $this->b->id, 'recorded_by' => 'x']);
        $g2->update(['pen_id' => $this->b->id]);
        $this->meal($this->a, $from->copy()->addDays(1)->toDateString(), 4, 2, 1); // PKR 400 split by 2
        $this->meal($this->a, $from->copy()->addDays(7)->toDateString(), 2, 1);    // PKR 200 to C-1 only

        $r = FeedCost::build(Carbon::parse($from), Carbon::parse($to));
        $c1 = $r['goats']->firstWhere('number', 'C-1');
        $c2 = $r['goats']->firstWhere('number', 'C-2');
        $this->assertEquals([400, 200], [$c1['cost'], $c2['cost']]);
        $this->assertEquals([2.0, 1.0], [$c1['gain'], $c2['gain']]);
        $this->assertEquals(200, $c1['costPerKg']);
        $this->assertEquals(1.6, $c1['fcr']); // ate (4 − 1) ÷ 2 + 2 = 3.5 KG as fed = 3.15 KG DM, ÷ 2 KG gain
        $pen = $r['pens']->firstWhere('id', $this->a->id);
        $this->assertSame(600, (int) $pen['cost']);
        $this->assertEquals(16.7, $pen['refusedPct']);
        $this->assertSame(600, (int) $r['totals']['cost']);
    }

    public function test_stock_count_posts_shrinkage_once_and_tmr_output_captures_mixing_loss(): void
    {
        FeedStockMovement::create(['feed_item_id' => $this->feed->id, 'moved_on' => now()->subDay(), 'type' => 'opening', 'qty_kg' => 100, 'recorded_by' => 'x']);
        $this->actingAs($this->user('caretaker'))->post('/feed-cost/count', ['counted_on' => now()->toDateString(), 'counts' => [['feed_item_id' => $this->feed->id, 'counted_kg' => 96]]])->assertForbidden();
        $this->actingAs($this->user('feeding'))->post('/feed-cost/count', ['counted_on' => now()->toDateString(), 'counts' => [['feed_item_id' => $this->feed->id, 'counted_kg' => 96]]])->assertSessionHasNoErrors();
        $c = FeedStockCount::sole();
        $this->assertEquals([100.0, -4.0, -40000], [$c->book_kg, $c->diff_kg, $c->value_minor]);
        $this->assertEquals(96.0, Feed::stock(true)[$this->feed->id]);

        $tmrItem = FeedItem::create(['code' => 'TMR', 'name' => 'TMR', 'category' => 'tmr']);
        $formula = TmrFormula::create(['name' => 'Finisher', 'feed_item_id' => $tmrItem->id, 'active' => true, 'created_by' => 'x']);
        $formula->lines()->create(['feed_item_id' => $this->feed->id, 'pct' => 100]);
        Tmr::mix($formula, 50, now()->toDateString(), $this->user('feeding'), null, 48);
        $this->assertEquals(48.0, (float) TmrBatch::sole()->output_kg);
        $this->assertEquals(48.0, Feed::stock(true)[$tmrItem->id]);
        $this->assertSame(10417, $tmrItem->fresh()->cost_per_kg_minor); // 50 KG × PKR 100 spread over 48 KG
    }

    public function test_a_shortfall_becomes_a_draft_purchase_order_and_poor_response_a_review(): void
    {
        $s = Supplier::create(['name' => 'Feed Mill']);
        $this->actingAs($this->user('feeding'))->post('/feed-cost/purchase', ['supplier_id' => $s->id, 'lines' => [['feed_item_id' => $this->feed->id, 'qty' => 300]]])->assertForbidden();
        $this->actingAs($this->user('manager'))->post('/feed-cost/purchase', ['supplier_id' => $s->id, 'lines' => [['feed_item_id' => $this->feed->id, 'qty' => 300]]])->assertSessionHasNoErrors();
        $po = PurchaseOrder::with('lines')->sole();
        $this->assertSame(['draft', 300.0, 10000], [$po->status, (float) $po->lines[0]->qty, (int) $po->lines[0]->rate_minor]);

        $this->actingAs($this->user('feeding'))->post("/feed-cost/pens/{$this->a->id}/review", ['issues' => 'Feed conversion 14'])->assertSessionHasNoErrors();
        $this->assertSame(2, Task::where('title', 'Feed response review: '.$this->a->fullName())->count());
        $this->actingAs($this->user('feeding'))->post("/feed-cost/pens/{$this->a->id}/review")->assertStatus(422);
        $this->actingAs($this->user('finance'))->get('/feed-cost')->assertOk();
    }
}
