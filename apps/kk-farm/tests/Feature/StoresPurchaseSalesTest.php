<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FeedItem;
use App\Models\JournalLine;
use App\Models\LedgerEntry;
use App\Models\Medicine;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\StoreItem;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Accounting;
use App\Support\Feed;
use App\Support\MedicineStock;
use App\Support\Stores;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-178: the general store, purchase orders received into all three stores, and sales to customers. */
class StoresPurchaseSalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Store Farm', 'slug' => 'store-farm'])->id);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@store.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_the_general_store_keeps_stock_and_refuses_to_issue_more_than_is_there(): void
    {
        $manager = $this->user('manager');
        $this->actingAs($manager)->post('/inventory/items', ['code' => 'bed-1', 'name' => 'Wheat straw bedding', 'category' => 'bedding', 'unit' => 'bale', 'reorder_level' => 5, 'cost' => 400, 'opening' => 8])->assertSessionHasNoErrors();
        $item = StoreItem::firstOrFail();
        $this->assertSame('BED-1', $item->code);

        $this->actingAs($manager)->post('/inventory/moves', ['store_item_id' => $item->id, 'type' => 'issue', 'qty' => 9, 'moved_on' => now()->toDateString()])->assertSessionHasErrors('qty');
        $this->actingAs($manager)->post('/inventory/moves', ['store_item_id' => $item->id, 'type' => 'issue', 'qty' => 4, 'moved_on' => now()->toDateString(), 'issued_to' => 'KKF-BDU-01'])->assertSessionHasNoErrors();
        $this->assertSame(4.0, Stores::generalQty()[$item->id]);
        $row = collect(Stores::overview())->firstWhere('kind', 'store');
        $this->assertSame('low', $row['status']); // 4 bales left, reorder at 5

        $this->actingAs($manager)->get('/inventory')->assertInertia(fn (AssertableInertia $p) => $p->has('overview', 1)->where('items.0.qty', 4));
        // D-241: each role sees its own store only
        $this->actingAs($this->user('caretaker'))->get('/inventory')->assertInertia(fn (AssertableInertia $p) => $p->where('stores', ['store'])->has('items', 1));
        $this->actingAs($this->user('vet'))->get('/inventory')->assertInertia(fn (AssertableInertia $p) => $p->where('stores', ['medicine'])->has('items', 0)->where('overview', fn ($o) => collect($o)->every(fn ($r) => $r['kind'] === 'medicine')));
        $this->actingAs($this->user('caretaker'))->post('/inventory/moves', ['store_item_id' => $item->id, 'type' => 'issue', 'qty' => 1, 'moved_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($this->user('feeding'))->post('/inventory/moves', ['store_item_id' => $item->id, 'type' => 'issue', 'qty' => 1, 'moved_on' => now()->toDateString()])->assertForbidden();
    }

    public function test_a_received_purchase_order_puts_goods_in_every_store_and_is_owed_to_the_supplier(): void
    {
        $manager = $this->user('manager');
        $feed = FeedItem::create(['code' => 'WND', 'name' => 'Wanda', 'category' => 'concentrate', 'cost_per_kg_minor' => 9000]);
        $med = Medicine::create(['code' => 'OXY', 'name' => 'Oxytetracycline', 'category' => 'antibiotic', 'unit' => 'ml']);
        $store = StoreItem::create(['code' => 'DIS', 'name' => 'Disinfectant', 'category' => 'cleaning', 'unit' => 'litre']);
        $this->actingAs($manager)->post('/purchase/suppliers', ['name' => 'Al-Noor Feeds', 'city' => 'Bahawalpur', 'supplies' => 'Feed'])->assertSessionHasNoErrors();
        $supplier = Supplier::firstOrFail();

        $this->actingAs($manager)->post('/purchase/orders', ['supplier_id' => $supplier->id, 'ordered_on' => now()->toDateString(), 'status' => 'ordered', 'lines' => [
            ['kind' => 'feed', 'item_id' => $feed->id, 'qty' => 500, 'rate' => 95],
            ['kind' => 'medicine', 'item_id' => $med->id, 'qty' => 200, 'rate' => 12],
            ['kind' => 'store', 'item_id' => $store->id, 'qty' => 10, 'rate' => 300],
            ['kind' => 'store', 'item_id' => 9999, 'qty' => 1, 'rate' => 1],
        ]])->assertSessionHasErrors('lines.3.item_id');
        $this->actingAs($manager)->post('/purchase/orders', ['supplier_id' => $supplier->id, 'ordered_on' => now()->toDateString(), 'status' => 'ordered', 'lines' => [
            ['kind' => 'feed', 'item_id' => $feed->id, 'qty' => 500, 'rate' => 95],
            ['kind' => 'medicine', 'item_id' => $med->id, 'qty' => 200, 'rate' => 12],
            ['kind' => 'store', 'item_id' => $store->id, 'qty' => 10, 'rate' => 300],
        ]])->assertSessionHasNoErrors();
        $order = PurchaseOrder::firstOrFail();
        $this->assertSame('PO-'.now()->format('ym').'-001', $order->po_no);

        // Medicine needs its expiry date when it arrives.
        $this->actingAs($manager)->post("/purchase/orders/{$order->id}/receive", ['received_on' => now()->toDateString(), 'paid' => false])->assertSessionHasErrors('lines.1.expiry_on');
        $this->actingAs($manager)->post("/purchase/orders/{$order->id}/receive", ['received_on' => now()->toDateString(), 'paid' => false, 'lines' => [[], ['batch' => 'B12', 'expiry_on' => now()->addYear()->toDateString()], []]])->assertSessionHasNoErrors();

        $this->assertSame('received', $order->fresh()->status);
        $this->assertEqualsWithDelta(500.0, Feed::stock(true)[$feed->id], 0.01);
        $this->assertEqualsWithDelta(200.0, (float) MedicineStock::quantities()[$med->id], 0.01);
        $this->assertSame(10.0, Stores::generalQty()[$store->id]);
        $this->assertSame(9500, $feed->fresh()->cost_per_kg_minor); // costed at the latest price
        $this->assertSame(['Farm Supplies', 'Feed Purchase', 'Medicine'], LedgerEntry::orderBy('category')->pluck('category')->all());
        $this->assertSame(['pending'], LedgerEntry::distinct()->pluck('payment_status')->all());
        $owed = JournalLine::where('account_id', Accounting::account('payable')->id)->sum('credit_minor');
        $this->assertSame(47500 * 100 + 2400 * 100 + 3000 * 100, (int) $owed); // 500×95 + 200×12 + 10×300, all owed

        $this->actingAs($manager)->post("/purchase/orders/{$order->id}/receive", ['received_on' => now()->toDateString(), 'paid' => true])->assertSessionHasErrors('received_on');
        $this->actingAs($manager)->get('/purchase')->assertInertia(fn (AssertableInertia $p) => $p->where('orders.0.total', 52900)->where('suppliers.0.spent', 52900));
        $this->actingAs($this->user('caretaker'))->get('/purchase')->assertForbidden();
    }

    public function test_product_sales_go_to_a_customer_and_count_in_the_sales_totals(): void
    {
        $finance = $this->user('finance');
        $this->actingAs($finance)->post('/sales/customers', ['name' => 'Rafiq Butcher', 'type' => 'butcher', 'city' => 'Lahore'])->assertSessionHasNoErrors();
        $customer = Customer::firstOrFail();
        $this->actingAs($finance)->post('/sales/products', ['sold_on' => now()->toDateString(), 'category' => 'product', 'item' => 'Milk 40 L', 'customer_id' => $customer->id, 'gross' => 6000, 'fee' => 200])->assertSessionHasNoErrors();

        $sale = Sale::firstOrFail();
        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertSame('Rafiq Butcher', $sale->buyer);
        $this->actingAs($finance)->get('/sales')->assertInertia(fn (AssertableInertia $p) => $p->where('kpis.month', 5800)->where('customers.0.total', 5800)->where('sales.0.buyer', 'Rafiq Butcher'));
        $this->actingAs($this->user('caretaker'))->get('/sales')->assertForbidden();
    }
}
