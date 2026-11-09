<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\AnimalMovement;
use App\Models\Breed;
use App\Models\HealthCase;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Sale;
use App\Models\Shed;
use App\Models\Treatment;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-076: animal movements (transfers, batch moves, exits) and sales. */
class MovementsSalesTest extends TestCase
{
    use RefreshDatabase;

    private Pen $a;

    private Pen $b;

    private Breed $breed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Move Farm', 'slug' => 'move-farm'])->id);
        $this->breed = Breed::create(['name' => 'Beetal', 'default_gestation_days' => 150]);
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $this->a = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->b = Pen::create(['shed_id' => $shed->id, 'name' => 'P02', 'purpose' => 'Growers']);
    }

    private function goat(string $n): Animal
    {
        return Animal::create(['farm_number' => $n, 'sex' => 'female', 'breed_id' => $this->breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $this->a->id]);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@move.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_the_dashboard_shows_every_shed_and_pen_with_its_goats_and_moves_them(): void
    {
        $g1 = $this->goat('G-1');
        $g2 = $this->goat('G-2');
        $loose = Animal::create(['farm_number' => 'L-1', 'sex' => 'male', 'breed_id' => $g1->breed_id, 'birth_date' => now()->subYear(), 'origin' => 'purchased']);
        $manager = $this->user('manager');

        // D-120: the board for moving goats is on the Sheds page (empty pens too, as drop targets) ...
        $this->actingAs($manager)->get('/sheds')->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
            ->where('board.sheds.0.pens.0.code', 'P01')->has('board.sheds.0.pens.0.goats', 2)
            ->where('board.sheds.0.pens.1.code', 'P02')->has('board.sheds.0.pens.1.goats', 0)
            ->where('board.noPen.0.number', 'L-1'));
        // ... and the dashboard shows only pens with goats, with their counts.
        $this->actingAs($manager)->get('/')->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p->missing('shedBoard')
            ->has('pensSummary.sheds.0.pens', 1)->where('pensSummary.sheds.0.pens.0.code', 'P01')->where('pensSummary.sheds.0.pens.0.goats', 2)->where('pensSummary.noPen', 1));

        // What the board sends when goats are dropped on another pen.
        $this->actingAs($manager)->post('/movements', ['animal_ids' => [$g2->id, $loose->id], 'type' => 'transfer', 'to_pen_id' => $this->b->id, 'moved_on' => now()->toDateString(), 'reason' => 'Moved on the dashboard'])->assertSessionHasNoErrors();
        $this->assertSame([$this->a->id, $this->b->id, $this->b->id], [$g1->fresh()->pen_id, $g2->fresh()->pen_id, $loose->fresh()->pen_id]);
        $this->assertSame(2, \App\Models\AnimalMovement::where('reason', 'Moved on the dashboard')->count());
        $this->actingAs($manager)->get('/sheds')->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p->has('board.sheds.0.pens.1.goats', 2)->where('board.noPen', []));

        $this->actingAs($this->user('finance'))->get('/sheds')->assertForbidden(); // the board where goats are moved
        $this->actingAs($this->user('finance'))->post('/movements', ['animal_ids' => [$g1->id], 'type' => 'transfer', 'to_pen_id' => $this->b->id, 'moved_on' => now()->toDateString()])->assertForbidden();
    }

    public function test_a_batch_of_goats_moves_to_a_pen_with_one_movement_each(): void
    {
        $g1 = $this->goat('G-1');
        $g2 = $this->goat('G-2');
        $this->actingAs($this->user('caretaker'))->post('/movements', ['animal_ids' => [$g1->id, $g2->id], 'type' => 'transfer', 'moved_on' => now()->toDateString(), 'reason' => 'sorting'])->assertSessionHasErrors('to_pen_id');
        $this->actingAs($this->user('caretaker'))->post('/movements', ['animal_ids' => [$g1->id, $g2->id], 'type' => 'transfer', 'to_pen_id' => $this->b->id, 'moved_on' => now()->toDateString(), 'reason' => 'sorting'])->assertSessionHasNoErrors();

        $this->assertSame([$this->b->id, $this->b->id], [$g1->fresh()->pen_id, $g2->fresh()->pen_id]);
        $this->assertSame(2, AnimalMovement::where('type', 'transfer')->where('from_pen_id', $this->a->id)->where('to_pen_id', $this->b->id)->count());

        // The Sheds page move goes through the same service.
        $this->actingAs($this->user('caretaker'))->post('/sheds/transfer', ['animal_id' => $g1->id, 'pen_id' => $this->a->id])->assertSessionHasNoErrors();
        $this->assertSame(3, AnimalMovement::count());
    }

    public function test_a_sale_closes_the_goat_and_records_the_sale_and_net_income_once(): void
    {
        $g1 = $this->goat('G-1');
        $g2 = $this->goat('G-2');
        $this->actingAs($this->user('caretaker'))->post('/movements', ['animal_ids' => [$g1->id], 'type' => 'sale', 'moved_on' => now()->toDateString(), 'amount' => 50000])->assertForbidden();
        $this->actingAs($this->user('manager'))->post('/movements', ['animal_ids' => [$g1->id, $g2->id], 'type' => 'sale', 'moved_on' => now()->toDateString()])->assertSessionHasErrors('amount');
        $this->actingAs($this->user('manager'))->post('/movements', ['animal_ids' => [$g1->id, $g2->id], 'type' => 'sale', 'moved_on' => now()->toDateString(), 'amount' => 50000, 'fee' => 2000, 'destination' => 'Mandi trader', 'evidence_ref' => 'R-77'])->assertSessionHasNoErrors();

        $g1->refresh();
        $this->assertSame(['left_farm', 'sold', null], [$g1->status, $g1->exit_reason, $g1->pen_id]);
        $this->assertSame(2, Sale::count());
        $sale = Sale::where('animal_id', $g1->id)->sole();
        $this->assertSame([5000000, 200000, 4800000, 'Mandi trader', 'R-77'], [$sale->gross_minor, $sale->fee_minor, $sale->netMinor(), $sale->buyer, $sale->receipt_ref]);
        $this->assertSame([2, 96000.0], [LedgerEntry::where('category', 'Animal sale')->count(), (float) LedgerEntry::where('category', 'Animal sale')->sum('amount')]);
        $this->actingAs($this->user('ceo'))->get('/finance')->assertInertia(fn (AssertableInertia $p) => $p->has('sales', 2)->where('sales.0.net', 48000));
    }

    public function test_one_goat_under_meat_withdrawal_stops_the_whole_batch(): void
    {
        $g1 = $this->goat('G-1');
        $g2 = $this->goat('G-2');
        $case = HealthCase::create(['case_no' => 1, 'animal_id' => $g2->id, 'onset_on' => now(), 'condition' => 'X', 'severity' => 'low', 'recorded_by' => 'x']);
        Treatment::create(['health_case_id' => $case->id, 'animal_id' => $g2->id, 'given_on' => now(), 'product' => 'P', 'dose' => '1', 'route' => 'oral', 'meat_clear_on' => now()->addDays(5), 'recorded_by' => 'x']);

        $this->actingAs($this->user('manager'))->post('/movements', ['animal_ids' => [$g1->id, $g2->id], 'type' => 'sacrificed', 'moved_on' => now()->toDateString()])->assertSessionHasErrors('type');
        $this->assertSame(['active', 'active'], [$g1->fresh()->status, $g2->fresh()->status], 'all or nothing');
        $this->actingAs($this->user('manager'))->post('/movements', ['animal_ids' => [$g2->id], 'type' => 'death', 'moved_on' => now()->toDateString(), 'reason' => 'pneumonia'])->assertSessionHasNoErrors();
        $this->assertSame('deceased', $g2->fresh()->status);
    }

    public function test_the_goat_page_exit_uses_the_same_movement_and_sale(): void
    {
        $g = $this->goat('G-1');
        $this->actingAs($this->user('manager'))->post("/goats/{$g->id}/exit", ['reason' => 'sold', 'amount' => 30000, 'destination' => 'Neighbour'])->assertSessionHasNoErrors();
        $this->assertSame(['sale', 3000000], [AnimalMovement::sole()->type, AnimalMovement::sole()->amount_minor]);
        $this->assertSame(3000000, Sale::sole()->gross_minor);
    }

    public function test_product_sales_go_to_sales_and_income_and_fees_cannot_exceed_the_price(): void
    {
        $this->actingAs($this->user('finance'))->post('/finance/sales', ['sold_on' => now()->toDateString(), 'category' => 'product', 'item' => 'Milk 40 L', 'gross' => 4000, 'fee' => 5000])->assertSessionHasErrors('fee');
        $this->actingAs($this->user('finance'))->post('/finance/sales', ['sold_on' => now()->toDateString(), 'category' => 'product', 'item' => 'Milk 40 L', 'gross' => 4000, 'buyer' => 'Dairy'])->assertSessionHasNoErrors();
        $this->assertSame(['Milk 40 L', null], [Sale::sole()->item, Sale::sole()->animal_id]);
        $this->assertSame(['in', 'Product sale', 4000.0], [LedgerEntry::sole()->direction, LedgerEntry::sole()->category, (float) LedgerEntry::sole()->amount]);
        $this->assertNull(LedgerEntry::sole()->shed_id, 'no longer filed under shed #1');
    }
}
