<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Customer;
use App\Models\HealthCase;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RfidTag;
use App\Models\SaleOrder;
use App\Models\SaleOrderLine;
use App\Models\SaleScan;
use App\Models\Shed;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Accounting;
use App\Support\Farm;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-194: V3 journey E Sale-to-Cash & Exit: clear goats only, approved pricing, RFID at loading, one exit, invoice, receivable, margin. */
class SaleToCashTest extends TestCase
{
    use RefreshDatabase;

    private array $g = [];

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Sell Farm', 'slug' => 'sell-farm'])->id);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Market']);
        $breed = Breed::create(['name' => 'Beetal']);
        foreach (['S-1', 'S-2', 'S-3'] as $i => $tag) {
            $a = Animal::create(['farm_number' => $tag, 'sex' => 'male', 'breed_id' => $breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $pen->id, 'status' => 'active',
                'purchase_cost_minor' => 3000000, 'animal_purpose' => 'sale']);
            WeightRecord::create(['animal_id' => $a->id, 'measured_on' => now()->subDay(), 'weight_kg' => 40, 'measured_by' => 'x']);
            if ($tag !== 'S-3') {
                Farm::assignTag($a, RfidTag::create(['tag_code' => "E2000000{$i}", 'status' => 'in_stock']), 'initial', $this->user('ceo'));
            }
            $this->g[$tag] = $a;
        }
        $this->customer = Customer::create(['name' => 'Lahore Mandi Trader', 'type' => 'trader']);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@sell.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function quote(array $tags, array $extra = [])
    {
        return $this->actingAs($this->user('finance'))->post('/sales/orders', $extra + ['customer_id' => $this->customer->id, 'basis' => 'per_head', 'terms' => 'credit', 'due_on' => now()->addDays(10)->toDateString(),
            'lines' => array_map(fn ($t) => ['animal_id' => $this->g[$t]->id, 'price' => 60000], $tags)]);
    }

    public function test_only_clear_goats_are_quoted(): void
    {
        Treatment::create(['health_case_id' => HealthCase::create(['case_no' => 1, 'animal_id' => $this->g['S-2']->id, 'onset_on' => now(), 'condition' => 'Fever', 'severity' => 'low', 'status' => 'recovered', 'recorded_by' => 'x'])->id,
            'animal_id' => $this->g['S-2']->id, 'given_on' => now(), 'product' => 'Oxytet', 'dose' => '5 ml', 'route' => 'injectable', 'meat_clear_on' => now()->addDays(20), 'recorded_by' => 'x']);
        $this->quote(['S-2'])->assertSessionHasErrors(['lines.0.animal_id' => 'S-2: Meat withdrawal until '.now()->addDays(20)->format('j M Y').'. Resolve it first.']);
        $this->quote(['S-3'])->assertSessionHasErrors('lines.0.animal_id'); // no RFID tag
        $this->quote(['S-1'])->assertSessionHasNoErrors();
        $this->quote(['S-1'])->assertSessionHasErrors('lines.0.animal_id'); // already on an order
    }

    public function test_approved_reserved_scanned_dispatched_invoiced_and_collected(): void
    {
        Farm::assignTag($this->g['S-3'], RfidTag::create(['tag_code' => 'E2000009', 'status' => 'in_stock']), 'initial', $this->user('ceo'));
        $this->quote(['S-1', 'S-2'])->assertSessionHasNoErrors();
        $order = SaleOrder::sole();
        $this->actingAs($this->user('finance'))->post("/sales/orders/{$order->id}/approve")->assertForbidden();
        $this->actingAs($this->user('manager'))->post("/sales/orders/{$order->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame(['reserved', 'reserved'], $order->lines()->pluck('status')->all());

        $scan = fn ($code) => $this->actingAs($this->user('manager'))->post("/sales/orders/{$order->id}/scan", ['code' => $code]);
        $scan('E20000000')->assertSessionHasNoErrors();
        $scan('E20000000')->assertSessionHasErrors('code');   // duplicate
        $scan('E2000009')->assertSessionHasErrors('code');    // S-3: substituted, not on the order
        $scan('NOPE')->assertSessionHasErrors('code');        // unknown
        $this->actingAs($this->user('manager'))->post("/sales/orders/{$order->id}/dispatch", ['dispatched_on' => now()->toDateString()])->assertSessionHasErrors('dispatch'); // S-2 not loaded
        $scan('S-2')->assertSessionHasNoErrors();
        $this->actingAs($this->user('manager'))->post("/sales/orders/{$order->id}/dispatch", ['dispatched_on' => now()->toDateString()])->assertSessionHasErrors('dispatch'); // checklist
        $this->actingAs($this->user('manager'))->post("/sales/orders/{$order->id}/checklist", ['done' => array_keys(SaleOrder::CHECKLIST)])->assertSessionHasNoErrors();
        $this->actingAs($this->user('manager'))->post("/sales/orders/{$order->id}/dispatch", ['dispatched_on' => now()->toDateString()])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(['dispatched', 'INV-'.now()->format('ym').'-001'], [$order->status, $order->invoice_no]);
        $this->assertSame(['left_farm', 'left_farm'], [$this->g['S-1']->fresh()->status, $this->g['S-2']->fresh()->status]);
        $line = SaleOrderLine::where('animal_id', $this->g['S-1']->id)->sole();
        $this->assertSame(3000000, $line->cost['purchase']);
        $entries = LedgerEntry::where('category', 'Animal sale')->get();
        $this->assertCount(2, $entries);
        $this->assertSame([6000000, 6000000], $entries->map(fn ($e) => Accounting::receivableMinor($e))->all()); // owed on credit
        $this->assertSame(3, SaleScan::where('result', '!=', 'match')->count());
        $this->actingAs($this->user('manager'))->post("/sales/orders/{$order->id}/dispatch", ['dispatched_on' => now()->toDateString()])->assertStatus(422); // no second exit

        $this->actingAs($this->user('finance'))->post("/sales/orders/{$order->id}/collect", ['collected_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame([0, 0], LedgerEntry::where('category', 'Animal sale')->get()->map(fn ($e) => Accounting::receivableMinor($e))->all());
        $this->assertNotNull($order->fresh()->collected_at);
    }
}
