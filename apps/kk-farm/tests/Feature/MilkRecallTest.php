<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Customer;
use App\Models\Lactation;
use App\Models\LedgerEntry;
use App\Models\MilkDispatch;
use App\Models\MilkLot;
use App\Models\MilkRecord;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\QualityCase;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Support\Quality;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-196: journey G Milk Quality-Hold-to-Recall (V3 flow 30). */
class MilkRecallTest extends TestCase
{
    use RefreshDatabase;

    private array $does = [];

    private array $lactations = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Recall Farm', 'slug' => 'recall-farm'])->id);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Lactation']);
        $breed = Breed::create(['name' => 'Beetal']);
        foreach (['D-1', 'D-3'] as $tag) {
            $a = Animal::create(['farm_number' => $tag, 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $pen->id]);
            $this->lactations[$tag] = Lactation::create(['animal_id' => $a->id, 'number' => 1, 'started_on' => now()->subDays(60), 'source' => 'kidding', 'recorded_by' => 'x']);
            $this->does[$tag] = $a;
        }
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@recall.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    /** Both does give 2 L at a session, then the caretaker closes it; returns the session's lot. */
    private function milkSession(string $day, string $session, array $mastitis = []): ?MilkLot
    {
        foreach ($this->does as $tag => $a) {
            MilkRecord::create(['id' => (string) Str::uuid(), 'animal_id' => $a->id, 'lactation_id' => $this->lactations[$tag]->id, 'milked_on' => $day, 'session' => $session, 'litres' => 2.0, 'discarded' => false, 'recorded_by' => 'x']);
        }
        $this->actingAs($this->user('caretaker'))->post('/dairy/sessions', ['milked_on' => $day, 'session' => $session, 'hygiene_ok' => true, 'equipment_ok' => true, 'operator' => 'Ali',
            'mastitis' => array_map(fn ($t) => $this->does[$t]->id, $mastitis), 'kid_l' => 0, 'discard_l' => 0])->assertSessionHasNoErrors();

        return MilkLot::latest('id')->first();
    }

    private function passAndRelease(MilkLot $lot): void
    {
        $this->actingAs($this->user('caretaker'))->post("/dairy/lots/{$lot->id}/test", ['appearance_ok' => true, 'smell_ok' => true, 'residue' => 'negative', 'tank' => 'T1', 'storage_temp_c' => 4])->assertSessionHasNoErrors();
        $this->actingAs($this->user('manager'))->post("/dairy/lots/{$lot->id}/release")->assertSessionHasNoErrors();
    }

    public function test_a_flagged_doe_holds_her_milk_and_a_recall_is_traced_and_settled(): void
    {
        $manager = $this->user('manager');
        $yesterday = now()->subDay()->toDateString();
        $customer = Customer::create(['name' => 'Bilal Dairy Shop', 'phone' => '0300-0000000', 'type' => 'trader']);

        // Yesterday morning: lot A, released, 2 of its 4 L sold on credit.
        $a = $this->milkSession($yesterday, 'morning');
        $this->passAndRelease($a);
        $this->actingAs($manager)->post("/dairy/lots/{$a->id}/dispatch", ['kind' => 'sale', 'litres' => 2, 'dispatched_on' => $yesterday, 'customer_id' => $customer->id, 'price_per_litre' => 200, 'terms' => 'credit', 'credit_days' => 15,
            'dispatch_temp_c' => 4, 'container_ok' => true, 'vehicle_ok' => true])->assertSessionHasNoErrors();

        // Yesterday evening: lot T; a storage reading above the limit holds it under a quality case.
        $t = $this->milkSession($yesterday, 'evening');
        $this->actingAs($this->user('caretaker'))->post("/dairy/lots/{$t->id}/test", ['appearance_ok' => true, 'smell_ok' => true, 'residue' => 'negative', 'storage_temp_c' => 4])->assertSessionHasNoErrors();
        $this->actingAs($this->user('caretaker'))->post("/dairy/lots/{$a->id}/temperature", ['temp_c' => 3.5])->assertSessionHasNoErrors(); // fine: no case
        $this->actingAs($this->user('caretaker'))->post("/dairy/lots/{$t->id}/temperature", ['temp_c' => 9])->assertSessionHasNoErrors();
        $this->assertSame('hold', $t->fresh()->status);
        $this->assertNotNull(Quality::milkLotHold($t->id));
        $this->assertSame(1, QualityCase::where('title', 'like', 'Temperature excursion%')->count());

        // Today morning: mastitis found in D-1. Her milk in lot A (still 2 L on the farm, part already sold) is held,
        // under one quality case, and a recall decision is raised for the part that left.
        $b = $this->milkSession(now()->toDateString(), 'morning', ['D-1']);
        $this->assertSame('hold', $a->fresh()->status);
        $case = QualityCase::where('animal_id', $this->does['D-1']->id)->firstOrFail();
        $this->assertSame(['milk', 'critical'], [$case->source, $case->severity]);
        $this->assertStringContainsString('Bilal Dairy Shop', $case->description);
        $this->assertTrue(Task::where('title', 'like', 'Decide milk recall%')->exists());
        $this->actingAs($manager)->post("/dairy/lots/{$a->id}/release")->assertStatus(422); // held milk cannot be released

        // Today's lot B fails the residue test: rejected, and a case asks to find the treated doe.
        $this->actingAs($this->user('caretaker'))->post("/dairy/lots/{$b->id}/test", ['appearance_ok' => true, 'smell_ok' => true, 'residue' => 'positive'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $b->fresh()->status);
        $this->assertTrue(QualityCase::where('title', "Antibiotic residue in {$b->code}")->where('description', 'like', '%D-3%')->exists());

        // Recipient trace of lot A: back to both does, forward to the customer and the invoice.
        $this->actingAs($manager)->get("/dairy?tab=trace&trace={$a->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->has('trace.does', 2)->where('trace.dispatches.0.to', 'Bilal Dairy Shop')->where('trace.dispatches.0.paid', false)->where('recallKpi.traceability', 100));

        // The customer complains; the quality lead recalls the lot.
        $sale = MilkDispatch::sole();
        $this->actingAs($this->user('caretaker'))->post("/dairy/dispatches/{$sale->id}/complaint", ['details' => 'Sour taste', 'severity' => 'high', 'recall' => true])->assertForbidden();
        $this->actingAs($manager)->post("/dairy/dispatches/{$sale->id}/complaint", ['details' => 'Sour taste', 'severity' => 'high', 'recall' => true])->assertSessionHasNoErrors();
        $this->assertSame('recalled', $a->fresh()->status);
        $this->assertTrue(QualityCase::where('source', 'complaint')->where('milk_lot_id', $a->id)->exists());

        // Settle the sale: still owed, so it is credited in full; the sale is reversed in the books.
        $this->actingAs($manager)->post("/dairy/dispatches/{$sale->id}/settle", ['kind' => 'refund', 'amount' => 100])->assertSessionHasErrors('kind'); // not paid yet
        $this->actingAs($manager)->post("/dairy/dispatches/{$sale->id}/settle", ['kind' => 'credit', 'returned_l' => 1.5])->assertSessionHasNoErrors();
        $sale->refresh();
        $this->assertSame(['credit', 40000, 1.5], [$sale->adjustment, (int) $sale->adjustment_minor, (float) $sale->returned_l]);
        $this->assertNotNull($sale->collected_at);
        $this->assertTrue(LedgerEntry::whereNotNull('reverses_id')->where('direction', 'out')->where('amount', 400)->exists());
        $this->actingAs($manager)->post("/dairy/dispatches/{$sale->id}/settle", ['kind' => 'credit'])->assertSessionHasErrors('kind'); // only once
    }

    public function test_a_late_treatment_with_a_milk_withdrawal_holds_milk_made_since_the_dose(): void
    {
        $vet = $this->user('vet');
        $lot = $this->milkSession(now()->subDay()->toDateString(), 'morning');
        $this->passAndRelease($lot);

        $case = \App\Support\MilkTrace::doeFlagged($this->does['D-3'], 'treated with Oxytetracycline, no milk until next week', now()->subDays(2)->toDateString(), $vet);
        $this->assertNotNull($case);
        $this->assertSame('hold', $lot->fresh()->status);
        $this->assertNull(\App\Support\MilkTrace::doeFlagged($this->does['D-3'], 'again', now()->subDays(2)->toDateString(), $vet)); // already held: no second case
    }
}
