<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\AnimalPurchaseItem;
use App\Models\AnimalPurchaseLot;
use App\Models\Breed;
use App\Models\HealthCase;
use App\Models\HealthRecord;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Support\Intake;
use App\Support\Movements;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-190: V3 journey A Purchase-to-Quarantine: approval by another person, inspection, quarantine pen, no mixing, cost once. */
class PurchaseToQuarantineTest extends TestCase
{
    use RefreshDatabase;

    private Pen $quarantine;

    private Pen $herd;

    private Breed $breed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Buy Farm', 'slug' => 'buy-farm'])->id);
        $this->breed = Breed::create(['name' => 'Beetal', 'code' => 'BT']);
        $this->quarantine = Pen::create(['shed_id' => Shed::create(['code' => 'BSU1', 'name' => 'KKF-BSU-01', 'type' => 'BSU'])->id, 'name' => 'P01', 'purpose' => 'Quarantine']);
        $this->herd = Pen::create(['shed_id' => Shed::create(['code' => 'GDU1', 'name' => 'KKF-GDU-01', 'type' => 'GDU'])->id, 'name' => 'P01', 'purpose' => 'Growers']);
        Animal::create(['farm_number' => 'H-1', 'sex' => 'male', 'breed_id' => $this->breed->id, 'birth_date' => now()->subYear(), 'origin' => 'born_on_farm', 'pen_id' => $this->herd->id, 'status' => 'active']);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@buy.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function as(string $role)
    {
        return $this->actingAs($this->user($role));
    }

    private function approvedLot(int $head = 3): AnimalPurchaseLot
    {
        $this->as('manager')->post('/purchase/animals', ['source' => 'Shahpur market', 'expected_head' => $head, 'budget' => 300000])->assertSessionHasNoErrors();
        $lot = AnimalPurchaseLot::latest('id')->first();
        $this->as('ceo')->post("/purchase/animals/{$lot->id}/decide", ['approve' => true])->assertSessionHasNoErrors(); // D-266: the manager raises it, the Administrator approves

        return $lot->fresh();
    }

    private function row(array $extra = []): array
    {
        return $extra + ['origin_ref' => 'MKT-'.random_int(100, 999), 'sex' => 'male', 'breed_id' => $this->breed->id, 'weight_kg' => 28, 'screening' => 'ok'];
    }

    public function test_the_request_is_approved_by_someone_else(): void
    {
        $this->as('manager')->post('/purchase/animals', ['source' => 'Breeder', 'expected_head' => 2, 'budget' => 100000])->assertSessionHasNoErrors();
        $lot = AnimalPurchaseLot::sole();
        $this->assertSame('APL-'.now()->format('Y').'-0001', $lot->lot_no);
        $this->as('manager')->post("/purchase/animals/{$lot->id}/decide", ['approve' => true])->assertForbidden(); // BR-027
        $this->as('finance')->post("/purchase/animals/{$lot->id}/decide", ['approve' => true])->assertForbidden();
        $this->as('vet')->post("/purchase/animals/{$lot->id}/receive", [])->assertStatus(422); // not approved yet
        $this->as('ceo')->post("/purchase/animals/{$lot->id}/decide", ['approve' => false])->assertSessionHasErrors('note');
        $this->as('ceo')->post("/purchase/animals/{$lot->id}/decide", ['approve' => true])->assertSessionHasNoErrors();
        $this->assertSame('approved', $lot->fresh()->status);
    }

    public function test_receiving_registers_accepted_goats_into_quarantine_with_lot_cost_and_one_bill(): void
    {
        $lot = $this->approvedLot(3);
        $base = ['received_on' => now()->toDateString(), 'quarantine_pen_id' => $this->quarantine->id, 'paid' => 200000, 'transport' => 10000, 'bill_paid' => false];
        $items = [$this->row(), $this->row(['screening' => 'sick', 'notes' => 'Nasal discharge', 'sex' => 'female']), $this->row(['screening' => 'rejected'])];

        $this->as('vet')->post("/purchase/animals/{$lot->id}/receive", $base + ['items' => $items])->assertSessionHasErrors('items.2.notes');
        $items[2]['notes'] = 'Lame, sent back';
        $this->as('vet')->post("/purchase/animals/{$lot->id}/receive", $base + ['items' => $items])->assertSessionHasErrors('discrepancy');
        $this->as('vet')->post("/purchase/animals/{$lot->id}/receive", ['quarantine_pen_id' => $this->herd->id, 'items' => $items, 'discrepancy' => 'x'] + $base)->assertSessionHasErrors('quarantine_pen_id'); // not dedicated
        $this->as('vet')->post("/purchase/animals/{$lot->id}/receive", $base + ['items' => $items, 'discrepancy' => 'One lame goat returned, price reduced'])->assertSessionHasNoErrors();

        $lot->refresh();
        $this->assertSame('received', $lot->status);
        $goats = Animal::where('purchase_lot_id', $lot->id)->get();
        $this->assertCount(2, $goats);
        $this->assertTrue($goats->every(fn ($g) => $g->pen_id === $this->quarantine->id && $g->purchase_cost_minor === 10500000)); // (200,000 + 10,000) / 2
        $this->assertEqualsCanonicalizing($goats->pluck('id')->all(), Intake::quarantinedIds());
        $this->assertSame(1, HealthRecord::where('title', 'like', 'Sick on arrival%')->count());
        $this->assertSame(1, AnimalPurchaseItem::whereNull('animal_id')->where('screening', 'rejected')->count());
        $this->assertEquals([200000.0, 10000.0], LedgerEntry::where('category', 'Animal purchase')->orderBy('id')->pluck('amount')->all());
        $this->assertSame('pending', LedgerEntry::find($lot->ledger_entry_id)->payment_status);
        $this->assertTrue(Task::where('title', "Reconcile goat purchase {$lot->lot_no}")->exists());
        $this->as('vet')->post("/purchase/animals/{$lot->id}/receive", $base + ['items' => $items])->assertStatus(422); // received once, posted once

        $this->as('manager')->post("/purchase/animals/{$lot->id}/reconcile", ['reconciliation' => 'Seller refunded PKR 100,000'])->assertSessionHasNoErrors();
        $this->as('ceo')->get('/purchase/animals')->assertInertia(fn (AssertableInertia $p) => $p->component('AnimalPurchase')->where('kpi.rejection', 33.3)->where('kpi.costPerHead', 105000)->where('kpi.inQuarantine', 2));
    }

    public function test_no_mixing_before_clearance_then_extend_or_clear_into_an_operating_pen(): void
    {
        $lot = $this->approvedLot(1);
        $this->as('vet')->post("/purchase/animals/{$lot->id}/receive", ['received_on' => now()->toDateString(), 'quarantine_pen_id' => $this->quarantine->id, 'paid' => 50000, 'bill_paid' => true, 'items' => [$this->row()]])->assertSessionHasNoErrors();
        $goat = Animal::where('purchase_lot_id', $lot->id)->sole();
        $case = HealthCase::where('animal_id', $goat->id)->sole();

        try {
            Movements::transfer($goat, $this->herd, $this->user('manager'));
            $this->fail('A quarantined goat was mixed with the herd.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('intake quarantine', $e->getMessage());
        }
        try {
            Movements::transfer(Animal::where('farm_number', 'H-1')->sole(), $this->quarantine, $this->user('manager'));
            $this->fail('A herd goat joined a quarantine pen.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('holds goats in intake quarantine', $e->getMessage());
        }

        $this->as('caretaker')->post("/intake/{$case->id}/extend", ['days' => 7, 'reason' => 'x'])->assertForbidden();
        $this->as('vet')->post("/intake/{$case->id}/extend", ['days' => 10, 'reason' => 'Coughing, treating and rechecking'])->assertSessionHasNoErrors();
        $this->assertSame(now()->addDays(10)->toDateString(), Task::where('title', "Intake clearance: {$goat->farm_number}")->sole()->due_on->toDateString());

        $this->as('vet')->post("/intake/{$case->id}/clear", ['pen_id' => $this->herd->id, 'notes' => 'Clear'])->assertSessionHasNoErrors();
        $this->assertSame([$this->herd->id, 'closed'], [$goat->fresh()->pen_id, $case->fresh()->status]);
    }
}
