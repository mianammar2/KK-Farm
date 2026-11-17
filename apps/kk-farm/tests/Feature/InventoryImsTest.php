<?php

namespace Tests\Feature;

use App\Models\FeedItem;
use App\Models\FeedStockCount;
use App\Models\FeedStockMovement;
use App\Models\Medicine;
use App\Models\MedicineMovement;
use App\Models\Organization;
use App\Models\StoreItem;
use App\Models\StoreMove;
use App\Models\User;
use App\Support\Feed;
use App\Support\MedicineStock;
use App\Support\Stores;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-331: the full inventory: every store's items and stock are kept from Inventory, through the same actions the apps use. */
class InventoryImsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'IMS Farm', 'slug' => 'ims-farm'])->id);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@ims.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_feed_and_medicine_added_in_inventory_are_the_same_records_the_apps_use(): void
    {
        $ceo = $this->user('ceo');
        $this->actingAs($ceo)->post('/inventory/feed-items', ['code' => 'conc', 'name' => 'Concentrate', 'category' => 'concentrate', 'cost_per_kg' => 120, 'opening_kg' => 50])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->post('/inventory/medicines', ['code' => 'oxy', 'name' => 'Oxytetracycline', 'category' => 'antibiotic', 'unit' => 'ml', 'opening_qty' => 100, 'batch' => 'B1', 'expiry_on' => now()->addDays(10)->toDateString()])->assertSessionHasNoErrors();
        $feed = FeedItem::firstOrFail();
        $med = Medicine::firstOrFail();
        $this->assertSame(50.0, (float) Feed::stock(true)[$feed->id]);
        $this->assertSame(100.0, (float) MedicineStock::quantities()[$med->id]);

        // stock in from Inventory, edit from Inventory: Feeding and Veterinary read the same rows
        $this->actingAs($ceo)->post('/inventory/feed-stock', ['feed_item_id' => $feed->id, 'type' => 'receipt', 'qty_kg' => 25, 'moved_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->post("/inventory/medicines/{$med->id}", ['name' => 'Oxytetracycline LA', 'category' => 'antibiotic', 'reorder_level' => 20, 'cost_per_unit' => 5])->assertSessionHasNoErrors();
        $this->assertSame(75.0, (float) Feed::stock(true)[$feed->id]);
        $this->assertSame('Oxytetracycline LA', $med->fresh()->name);
        $this->actingAs($ceo)->get('/feeding?tab=store')->assertOk();

        $this->actingAs($ceo)->get('/inventory')->assertInertia(fn (AssertableInertia $p) => $p->component('Inventory')->has('items', 2)
            ->where('expiry.0.batch', 'B1')->where('expiry.0.state', 'expiring')
            ->where('moves', fn ($m) => collect($m)->contains(fn ($r) => $r['kind'] === 'feed' && $r['typeKey'] === 'receipt' && $r['qty'] == 25)));

        // the item card: its details, batches and every movement
        $card = $this->actingAs($ceo)->getJson("/inventory/card?kind=medicine&id={$med->id}")->assertOk()->json();
        $this->assertSame('Oxytetracycline LA', $card['item']['name']);
        $this->assertSame('B1', $card['batches'][0]['batch']);
        $this->assertCount(1, $card['moves']);
    }

    public function test_each_role_works_only_in_its_own_store(): void
    {
        $this->actingAs($this->user('vet'))->post('/inventory/feed-items', ['code' => 'x', 'name' => 'X', 'category' => 'concentrate', 'cost_per_kg' => 1])->assertForbidden();
        $this->actingAs($this->user('feeding'))->post('/inventory/medicines', ['code' => 'y', 'name' => 'Y', 'category' => 'antibiotic', 'unit' => 'ml'])->assertForbidden();
        $med = Medicine::create(['code' => 'M', 'name' => 'M', 'category' => 'antibiotic', 'unit' => 'ml']);
        $this->actingAs($this->user('feeding'))->getJson("/inventory/card?kind=medicine&id={$med->id}")->assertForbidden();
        $this->actingAs($this->user('feeding'))->post('/inventory/count', ['kind' => 'medicine', 'counted_on' => now()->toDateString(), 'counts' => [['id' => $med->id, 'counted' => 1]]])->assertForbidden();
    }

    public function test_a_stock_count_posts_each_difference_once_in_every_store(): void
    {
        $ceo = $this->user('ceo');
        $feed = FeedItem::create(['code' => 'F', 'name' => 'Straw', 'category' => 'roughage', 'cost_per_kg_minor' => 3000]);
        FeedStockMovement::create(['feed_item_id' => $feed->id, 'moved_on' => now()->toDateString(), 'type' => 'opening', 'qty_kg' => 100, 'recorded_by' => 't']);
        $med = Medicine::create(['code' => 'M', 'name' => 'Ivermectin', 'category' => 'dewormer', 'unit' => 'ml', 'cost_per_unit_minor' => 1000]);
        MedicineMovement::create(['medicine_id' => $med->id, 'moved_on' => now()->toDateString(), 'type' => 'opening', 'qty' => 50, 'recorded_by' => 't']);
        $gen = StoreItem::create(['code' => 'G', 'name' => 'Lime', 'category' => 'cleaning', 'unit' => 'bag', 'cost_per_unit_minor' => 50000]);
        StoreMove::create(['store_item_id' => $gen->id, 'moved_on' => now()->toDateString(), 'type' => 'opening', 'qty' => 10, 'recorded_by' => 't']);
        $on = now()->toDateString();

        $this->actingAs($ceo)->post('/inventory/count', ['kind' => 'feed', 'counted_on' => $on, 'counts' => [['id' => $feed->id, 'counted' => 92]]])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->post('/inventory/count', ['kind' => 'medicine', 'counted_on' => $on, 'counts' => [['id' => $med->id, 'counted' => 50]]])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->post('/inventory/count', ['kind' => 'store', 'counted_on' => $on, 'counts' => [['id' => $gen->id, 'counted' => 12]], 'notes' => 'Found in shed 2'])->assertSessionHasNoErrors();

        $this->assertSame(92.0, (float) Feed::stock(true)[$feed->id]);
        $this->assertSame(-8.0, FeedStockCount::firstOrFail()->diff_kg); // the feed count record is kept as before (D-192)
        $this->assertSame(0, MedicineMovement::where('type', 'adjustment')->count()); // nothing to post when the count matches
        $this->assertSame(12.0, Stores::generalQty()[$gen->id]);
        $this->assertStringContainsString('Found in shed 2', StoreMove::where('type', 'adjustment')->value('notes'));
        $this->actingAs($ceo)->post('/inventory/count', ['kind' => 'store', 'counted_on' => $on, 'counts' => [['id' => $gen->id, 'counted' => -1]]])->assertSessionHasErrors('counts.0.counted');
    }
}
