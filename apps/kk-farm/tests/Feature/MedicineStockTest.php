<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthCase;
use App\Models\LedgerEntry;
use App\Models\Medicine;
use App\Models\MedicineMovement;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Treatment;
use App\Models\User;
use App\Support\MedicineStock;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-074: medicine store, batches and expiry, and treatments issuing stock. */
class MedicineStockTest extends TestCase
{
    use RefreshDatabase;

    private HealthCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Med Farm', 'slug' => 'med-farm'])->id);
        $breed = Breed::create(['name' => 'Beetal', 'default_gestation_days' => 150]);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $goat = Animal::create(['farm_number' => 'G-1', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $pen->id]);
        $this->case = HealthCase::create(['case_no' => 1, 'animal_id' => $goat->id, 'onset_on' => now(), 'condition' => 'Pneumonia', 'severity' => 'high', 'recorded_by' => 'x']);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@med.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function medicine(float $opening = 0, ?string $batch = null, ?string $expiry = null): Medicine
    {
        $this->actingAs($this->user('manager'))->post('/health/medicines', array_filter(['code' => 'oxy', 'name' => 'Oxytetracycline LA', 'category' => 'antibiotic', 'unit' => 'ml', 'reorder_level' => 50, 'cost_per_unit' => 12, 'opening_qty' => $opening, 'batch' => $batch, 'expiry_on' => $expiry]))->assertSessionHasNoErrors();

        return Medicine::where('code', 'OXY')->firstOrFail();
    }

    private function receive(Medicine $m, float $qty, string $batch, string $expiry, float $rate = 15)
    {
        return $this->actingAs($this->user('vet'))->post('/health/medicine-stock', ['medicine_id' => $m->id, 'type' => 'purchase', 'qty' => $qty, 'batch' => $batch, 'expiry_on' => $expiry, 'rate' => $rate, 'moved_on' => now()->toDateString(), 'party' => 'Vet Store']);
    }

    private function treat(Medicine $m, float $qty)
    {
        return $this->actingAs($this->user('vet'))->post("/health/cases/{$this->case->id}/treatments", ['medicine_id' => $m->id, 'qty_used' => $qty, 'dose' => "{$qty} ml", 'route' => 'injectable', 'given_on' => now()->toDateString(), 'no_withdrawal' => true]);
    }

    public function test_a_purchase_needs_an_expiry_records_the_expense_and_adds_a_batch(): void
    {
        $m = $this->medicine(20, 'OLD-1', now()->addYear()->toDateString());
        $this->actingAs($this->user('vet'))->post('/health/medicine-stock', ['medicine_id' => $m->id, 'type' => 'purchase', 'qty' => 100, 'batch' => 'B2', 'rate' => 15, 'moved_on' => now()->toDateString()])->assertSessionHasErrors('expiry_on');
        $this->receive($m, 100, 'B2', now()->addMonths(6)->toDateString())->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(120, MedicineStock::quantities()[$m->id], 0.001);
        $this->assertSame(['B2', 'OLD-1'], MedicineStock::batches()[$m->id]->pluck('batch')->all(), 'earliest expiry first');
        $this->assertSame(['out', 'Medicine', 1500.0], [LedgerEntry::sole()->direction, LedgerEntry::sole()->category, (float) LedgerEntry::sole()->amount]);
        $this->assertSame(1500, $m->fresh()->cost_per_unit_minor);
    }

    public function test_a_treatment_issues_from_the_batch_that_expires_first_and_never_an_expired_one(): void
    {
        $m = $this->medicine();
        $this->receive($m, 10, 'EXPIRED', now()->subDay()->toDateString())->assertSessionHasNoErrors();
        $this->receive($m, 6, 'SOON', now()->addMonth()->toDateString());
        $this->receive($m, 50, 'LATER', now()->addYear()->toDateString());

        $this->treat($m, 8)->assertSessionHasNoErrors();
        $issued = MedicineMovement::where('type', 'issue')->orderBy('id')->get(['batch', 'qty'])->map(fn ($x) => [$x->batch, $x->qty])->all();
        $this->assertSame([['SOON', -6.0], ['LATER', -2.0]], $issued);
        $t = Treatment::sole();
        $this->assertSame(['Oxytetracycline LA', 8.0, 12000], [$t->product, (float) $t->qty_used, $t->cost_minor], 'named and priced from the store (8 ml × PKR 15)');
        $this->assertSame('expired', MedicineStock::status(MedicineStock::quantities()[$m->id], 50, MedicineStock::batches()[$m->id]));
    }

    public function test_a_shortfall_is_recorded_and_shown_as_stock_below_zero(): void
    {
        $m = $this->medicine(3, 'B1', now()->addYear()->toDateString());
        $this->treat($m, 5)->assertSessionHas('success', fn ($msg) => str_contains($msg, '2 ml short'));
        $this->assertEqualsWithDelta(-2, MedicineStock::quantities()[$m->id], 0.001);
        $this->assertSame('negative', MedicineStock::status(-2, 50, collect()));
    }

    public function test_stock_without_batch_numbers_still_covers_a_treatment(): void
    {
        $m = $this->medicine(30);
        $this->treat($m, 5)->assertSessionHas('success', fn ($msg) => ! str_contains($msg, 'short'));
        $this->assertEqualsWithDelta(25, MedicineStock::quantities()[$m->id], 0.001);
    }

    public function test_reorder_and_expiring_statuses(): void
    {
        $soon = collect([['batch' => 'X', 'expiry' => now()->addDays(20), 'qty' => 100]]);
        $this->assertSame('reorder', MedicineStock::status(40, 50, collect()));
        $this->assertSame('expiring', MedicineStock::status(100, 50, $soon));
        $this->assertSame('ok', MedicineStock::status(100, 50, collect([['batch' => 'Y', 'expiry' => now()->addYear(), 'qty' => 100]])));
    }

    public function test_only_the_owner_manager_and_vet_change_the_store(): void
    {
        $m = $this->medicine();
        $this->actingAs($this->user('caretaker'))->post('/health/medicines', ['code' => 'X', 'name' => 'X', 'category' => 'other', 'unit' => 'ml'])->assertForbidden();
        $this->actingAs($this->user('caretaker'))->post('/health/medicine-stock', ['medicine_id' => $m->id, 'type' => 'disposal', 'qty' => 1, 'moved_on' => now()->toDateString()])->assertForbidden();
        $this->actingAs($this->user('vet'))->post('/health/medicine-stock', ['medicine_id' => $m->id, 'type' => 'adjustment', 'qty' => 1, 'moved_on' => now()->toDateString()])->assertSessionHasErrors('direction');
        $this->actingAs($this->user('ceo'))->get('/health')->assertOk();
    }
}
