<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\MilkDispatch;
use App\Models\MilkLot;
use App\Models\MilkOrder;
use App\Models\MilkSession;
use App\Models\Organization;
use App\Models\User;
use App\Support\Accounting;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-195: V3 journey F Lactation-to-Milk-to-Cash: milk order → released lot → transport checks → invoice on credit → collected. */
class LactationToCashTest extends TestCase
{
    use RefreshDatabase;

    private MilkLot $lot;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Milk Farm', 'slug' => 'milk-farm'])->id);
        $s = MilkSession::create(['milked_on' => now()->toDateString(), 'session' => 'morning', 'hygiene_ok' => true, 'equipment_ok' => true, 'operator' => 'Ali',
            'produced_l' => 25, 'held_l' => 2, 'colostrum_l' => 1, 'kid_l' => 2, 'discard_l' => 0, 'saleable_l' => 20, 'closed_by' => 'x']);
        $this->lot = MilkLot::create(['code' => 'MLK-T-001', 'session_id' => $s->id, 'litres' => 20, 'status' => 'released', 'tested_by' => 'a', 'released_by' => 'b']);
        $this->customer = Customer::create(['name' => 'Gulberg Dairy Shop', 'type' => 'retailer']);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@milk.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_an_order_is_filled_from_a_released_lot_after_transport_checks_and_collected_on_credit(): void
    {
        $this->actingAs($this->user('manager'))->post('/dairy/orders', ['customer_id' => $this->customer->id, 'litres' => 15, 'price_per_litre' => 220, 'wanted_on' => now()->toDateString(), 'terms' => 'credit', 'credit_days' => 7])->assertSessionHasNoErrors();
        $order = MilkOrder::sole();
        $send = fn (array $x) => $this->actingAs($this->user('manager'))->post("/dairy/lots/{$this->lot->id}/dispatch", $x + ['kind' => 'sale', 'litres' => 10, 'dispatched_on' => now()->toDateString(), 'order_id' => $order->id]);

        $send(['dispatch_temp_c' => 9, 'container_ok' => true, 'vehicle_ok' => true])->assertSessionHasErrors('dispatch_temp_c'); // too warm (7 °C max)
        $send(['dispatch_temp_c' => 4, 'container_ok' => false, 'vehicle_ok' => true])->assertSessionHasErrors('dispatch_temp_c');
        $send(['dispatch_temp_c' => 4, 'container_ok' => true, 'vehicle_ok' => true, 'litres' => 16])->assertSessionHasErrors('litres'); // more than the order
        $send(['dispatch_temp_c' => 4, 'container_ok' => true, 'vehicle_ok' => true])->assertSessionHasNoErrors();

        $d = MilkDispatch::sole();
        $this->assertSame(['MINV-'.now()->format('ym').'-001', $order->id, now()->addDays(7)->toDateString()], [$d->invoice_no, $d->order_id, $d->due_on->toDateString()]);
        $entry = LedgerEntry::where('direction', 'in')->sole();
        $this->assertEquals(2200.0, $entry->amount);
        $this->assertSame(220000, Accounting::receivableMinor($entry)); // owed by the shop
        $this->assertEquals(5.0, $order->fresh()->remaining());

        $send(['dispatch_temp_c' => 4, 'container_ok' => true, 'vehicle_ok' => true, 'litres' => 5])->assertSessionHasNoErrors();
        $this->assertSame('fulfilled', $order->fresh()->status);

        $this->actingAs($this->user('manager'))->get('/dairy?tab=money')->assertInertia(fn (AssertableInertia $p) => $p->where('kpi.owed', 3300)->has('invoices', 2)->where('monthly.2.sold', 15));
        $this->actingAs($this->user('finance'))->post("/dairy/dispatches/{$d->id}/collect", ['collected_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(0, Accounting::receivableMinor($entry->fresh()));
        $this->actingAs($this->user('finance'))->post("/dairy/dispatches/{$d->id}/collect", ['collected_on' => now()->toDateString()])->assertStatus(422);
    }

    public function test_processing_needs_no_transport_checks_or_invoice(): void
    {
        $this->actingAs($this->user('manager'))->post("/dairy/lots/{$this->lot->id}/dispatch", ['kind' => 'processing', 'litres' => 20, 'dispatched_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertNull(MilkDispatch::sole()->invoice_no);
        $this->assertSame('dispatched', $this->lot->fresh()->status);
    }
}
