<?php

namespace Tests\Feature;

use App\Models\FeedItem;
use App\Models\FeedStockMovement;
use App\Models\FodderCrop;
use App\Models\FodderField;
use App\Models\FodderLot;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\TmrFormula;
use App\Models\TmrFormulaLine;
use App\Models\User;
use App\Support\Feed;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-202: App 22 Fodder & Pasture and journey M Fodder-to-TMR (V3 flows 22 and 36). */
class FodderToTmrTest extends TestCase
{
    use RefreshDatabase;

    public function test_own_fodder_is_tested_released_at_its_cost_per_kg_dm_and_unsafe_lots_never_enter_stock(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Fodder Farm', 'slug' => 'fodder-farm'])->id);
        $u = fn (string $role) => User::firstOrCreate(['email' => "$role@fodder.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
        [$owner, $manager, $feeder] = [$u('ceo'), $u('manager'), $u('feeding')];

        // Optional app: off by default; only the owner can open it, to turn it on.
        $this->actingAs($manager)->get('/fodder')->assertForbidden();
        $this->actingAs($owner)->get('/fodder')->assertInertia(fn (AssertableInertia $p) => $p->where('enabled', false));
        $this->actingAs($owner)->post('/fodder/toggle', ['enabled' => true])->assertSessionHasNoErrors();

        $this->actingAs($manager)->post('/fodder/fields', ['name' => 'North Block', 'area_acres' => 10, 'irrigation' => 'tubewell'])->assertSessionHasNoErrors();
        $field = FodderField::sole();
        $this->actingAs($manager)->post('/fodder/crops', ['field_id' => $field->id, 'crop' => 'maize', 'area_acres' => 12, 'sown_on' => now()->subDays(80)->toDateString(), 'expected_yield_kg' => 50000])->assertSessionHasErrors('area_acres');
        $this->actingAs($manager)->post('/fodder/crops', ['field_id' => $field->id, 'crop' => 'maize', 'area_acres' => 5, 'sown_on' => now()->subDays(80)->toDateString(), 'expected_yield_kg' => 50000])->assertSessionHasNoErrors();
        $crop = FodderCrop::sole();
        foreach ([['seed', 20000], ['fertiliser', 30000]] as [$kind, $cost]) {
            $this->actingAs($feeder)->post("/fodder/crops/{$crop->id}/inputs", ['kind' => $kind, 'applied_on' => now()->subDays(70)->toDateString(), 'cost' => $cost])->assertSessionHasNoErrors();
        }
        $this->actingAs($feeder)->post("/fodder/crops/{$crop->id}/inputs", ['kind' => 'chemical', 'applied_on' => now()->subDays(5)->toDateString(), 'product' => 'Lambda-cyhalothrin'])->assertSessionHasErrors('phi_days');
        $this->actingAs($feeder)->post("/fodder/crops/{$crop->id}/inputs", ['kind' => 'chemical', 'applied_on' => now()->subDays(5)->toDateString(), 'product' => 'Lambda-cyhalothrin', 'phi_days' => 14])->assertSessionHasNoErrors();

        // No cutting for feed inside the spray's pre-harvest interval.
        $this->actingAs($feeder)->post("/fodder/crops/{$crop->id}/harvest", ['harvested_on' => now()->toDateString(), 'form' => 'silage', 'fresh_kg' => 20000])->assertSessionHasErrors('harvested_on');
        $this->travel(10)->days();
        $this->actingAs($feeder)->post("/fodder/crops/{$crop->id}/harvest", ['harvested_on' => now()->toDateString(), 'form' => 'silage', 'fresh_kg' => 20000])->assertSessionHasNoErrors();
        $lot = FodderLot::sole();

        // Untested: held. Aflatoxin above the limit: still held. Within it: released by the manager, not the harvester.
        $this->actingAs($manager)->post("/fodder/lots/{$lot->id}/decide", ['decision' => 'release'])->assertSessionHasErrors('lot');
        $tests = ['dm_pct' => 35, 'dm_method' => 'oven', 'mould' => false, 'smell_ok' => true, 'nitrate' => 'low', 'cp_pct' => 8.5];
        $this->actingAs($feeder)->post("/fodder/lots/{$lot->id}/test", $tests + ['aflatoxin_ppb' => 30])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/fodder/lots/{$lot->id}/decide", ['decision' => 'release'])->assertSessionHasErrors('lot');
        $this->actingAs($feeder)->post("/fodder/lots/{$lot->id}/test", $tests + ['aflatoxin_ppb' => 8])->assertSessionHasNoErrors();
        $this->actingAs($feeder)->post("/fodder/lots/{$lot->id}/decide", ['decision' => 'release'])->assertForbidden();
        $this->actingAs($manager)->post("/fodder/lots/{$lot->id}/decide", ['decision' => 'release'])->assertSessionHasNoErrors();

        // 20,000 of 50,000 KG expected carries 40 % of PKR 50,000 inputs: PKR 1/KG as fed, PKR 2.86/KG DM.
        $lot->refresh();
        $this->assertSame([2000000, 100, 286], [(int) $lot->cost_minor, (int) $lot->cost_per_kg_minor, (int) $lot->cost_per_kg_dm_minor]);
        $item = FeedItem::findOrFail($lot->feed_item_id);
        $this->assertSame(['FOD-MAIZE-SILAGE', 35.0, 100, 'roughage'], [$item->code, (float) $item->dm_pct, (int) $item->cost_per_kg_minor, $item->category]);
        $this->assertEqualsWithDelta(20000.0, Feed::stock(true)[$item->id], 0.01);
        $this->assertSame('receipt', FeedStockMovement::sole()->type);
        $this->assertSame(50000.0, (float) LedgerEntry::where('category', 'Fodder Production')->sum('amount')); // posted once, at input
        $this->assertSame(1, LedgerEntry::count() - 1); // inputs only: no second expense for the stock receipt

        // A TMR formula can now use it.
        $formula = TmrFormula::create(['feed_item_id' => FeedItem::create(['code' => 'TMR1', 'name' => 'Grower TMR', 'category' => 'tmr'])->id, 'name' => 'Grower TMR', 'active' => true, 'created_by' => 'x']);
        TmrFormulaLine::create(['tmr_formula_id' => $formula->id, 'feed_item_id' => $item->id, 'pct' => 60]);

        // Final cut with high nitrate: never released, rejected; the crop closes.
        $this->actingAs($feeder)->post("/fodder/crops/{$crop->id}/harvest", ['harvested_on' => now()->toDateString(), 'form' => 'green_chop', 'fresh_kg' => 25000, 'final_cut' => true])->assertSessionHasNoErrors();
        $last = FodderLot::latest('id')->first();
        $this->actingAs($feeder)->post("/fodder/lots/{$last->id}/test", ['dm_pct' => 22, 'dm_method' => 'microwave', 'mould' => false, 'smell_ok' => true, 'nitrate' => 'high'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/fodder/lots/{$last->id}/decide", ['decision' => 'release'])->assertSessionHasErrors('lot');
        $this->actingAs($manager)->post("/fodder/lots/{$last->id}/decide", ['decision' => 'reject', 'note' => 'High nitrate after drought'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $crop->fresh()->status);
        $this->assertSame(1, FeedStockMovement::count());

        $this->actingAs($manager)->get('/fodder?tab=report')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('kpi.costPerKgDm', 286)->where('kpi.rejectRate', 50)->where('kpi.released', 1)->where('kpi.yieldPerAcre', 9000)
            ->where('lots.1.formulas.0.name', 'Grower TMR'));
    }
}
