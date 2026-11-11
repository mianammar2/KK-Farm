<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Lactation;
use App\Models\LedgerEntry;
use App\Models\MilkLot;
use App\Models\MilkRecord;
use App\Models\MilkSession;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-188: App 17 along V3 flow 17, with BR-009 colostrum, BR-010 held milk, BR-012 release, BR-013 trace, BR-014 balance, BR-027 release ≠ tester. */
class DairyTest extends TestCase
{
    use RefreshDatabase;

    private array $does = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Dairy Farm', 'slug' => 'dairy-farm'])->id);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Lactation']);
        $breed = Breed::create(['name' => 'Beetal']);
        foreach (['D-1' => 60, 'D-2' => 1, 'D-3' => 40] as $tag => $dim) { // D-2 kidded yesterday: colostrum
            $a = Animal::create(['farm_number' => $tag, 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $pen->id]);
            $l = Lactation::create(['animal_id' => $a->id, 'number' => 1, 'started_on' => now()->subDays($dim), 'source' => 'kidding', 'recorded_by' => 'x']);
            MilkRecord::create(['id' => (string) Str::uuid(), 'animal_id' => $a->id, 'lactation_id' => $l->id, 'milked_on' => now()->toDateString(), 'session' => 'morning', 'litres' => 2.0, 'discarded' => false, 'recorded_by' => 'x']);
            $this->does[$tag] = $a;
        }
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@dairy.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function close(array $extra = [])
    {
        return $this->actingAs($this->user('caretaker'))->post('/dairy/sessions', $extra + ['milked_on' => now()->toDateString(), 'session' => 'morning', 'hygiene_ok' => true, 'equipment_ok' => true, 'operator' => 'Ali', 'mastitis' => [], 'kid_l' => 0, 'discard_l' => 0]);
    }

    public function test_a_session_balances_and_only_released_milk_is_sold_and_traced(): void
    {
        $this->close(['kid_l' => 5])->assertSessionHasErrors('kid_l'); // BR-014: more than is left
        $this->close(['mastitis' => [$this->does['D-3']->id], 'kid_l' => 0.5])->assertSessionHasNoErrors();
        $s = MilkSession::sole();
        // 6 L produced: D-3 held for mastitis (BR-010), D-2 colostrum (BR-009), 0.5 kids → 1.5 L saleable
        $this->assertSame([6.0, 2.0, 2.0, 0.5, 1.5], [$s->produced_l, $s->held_l, $s->colostrum_l, $s->kid_l, $s->saleable_l]);
        $this->assertTrue(Task::where('title', 'Mastitis check: D-3')->exists());
        $lot = MilkLot::sole();
        $this->assertSame(['MLK-'.now()->format('ymd').'-001', 'pending_qc', 1.5], [$lot->code, $lot->status, $lot->litres]);

        $sell = fn () => $this->actingAs($this->user('manager'))->post("/dairy/lots/{$lot->id}/dispatch", ['kind' => 'sale', 'litres' => 1.5, 'dispatched_on' => now()->toDateString(), 'buyer' => 'Dairy shop', 'price_per_litre' => 200, 'dispatch_temp_c' => 4, 'container_ok' => true, 'vehicle_ok' => true]);
        $sell()->assertSessionHasErrors('litres'); // BR-012: not released
        $this->actingAs($this->user('caretaker'))->post("/dairy/lots/{$lot->id}/test", ['appearance_ok' => true, 'smell_ok' => true, 'residue' => 'negative', 'tank' => 'T1', 'storage_temp_c' => 4])->assertSessionHasNoErrors();
        $this->assertSame('stored', $lot->fresh()->status);
        $this->actingAs($this->user('caretaker'))->post("/dairy/lots/{$lot->id}/release")->assertForbidden();
        $this->actingAs($this->user('manager'))->post("/dairy/lots/{$lot->id}/release")->assertSessionHasNoErrors();
        $sell()->assertSessionHasNoErrors();
        $this->assertSame('dispatched', $lot->fresh()->status);
        $this->assertEquals(300, LedgerEntry::where('direction', 'in')->value('amount'));

        $this->actingAs($this->user('manager'))->post("/dairy/lots/{$lot->id}/recall", ['reason' => 'Customer complaint'])->assertSessionHasNoErrors();
        $this->assertTrue(Task::where('title', "Milk recall {$lot->code}: notify buyers")->where('notes', 'like', '%Dairy shop%')->exists()); // BR-013
        $this->actingAs($this->user('manager'))->get('/dairy?tab=trace')->assertInertia(fn (AssertableInertia $p) => $p->where('lots.0.status', 'recalled')->where('lots.0.buyers.0.to', 'Dairy shop'));
    }

    public function test_a_failed_residue_check_rejects_the_lot_and_the_tester_cannot_release(): void
    {
        $this->close()->assertSessionHasNoErrors();
        $lot = MilkLot::sole();
        $this->actingAs($this->user('vet'))->post("/dairy/lots/{$lot->id}/test", ['appearance_ok' => true, 'smell_ok' => true, 'residue' => 'positive'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $lot->fresh()->status);

        foreach ($this->does as $a) { // evening litres for the second lot
            MilkRecord::create(['id' => (string) Str::uuid(), 'animal_id' => $a->id, 'lactation_id' => Lactation::where('animal_id', $a->id)->value('id'), 'milked_on' => now()->toDateString(), 'session' => 'evening', 'litres' => 2.0, 'discarded' => false, 'recorded_by' => 'x']);
        }
        $this->close(['session' => 'evening'])->assertSessionHasNoErrors();
        $lot2 = MilkLot::where('status', 'pending_qc')->sole();
        $this->actingAs($this->user('vet'))->post("/dairy/lots/{$lot2->id}/test", ['appearance_ok' => true, 'smell_ok' => true, 'residue' => 'negative'])->assertSessionHasNoErrors();
        $this->actingAs($this->user('vet'))->post("/dairy/lots/{$lot2->id}/release")->assertForbidden(); // BR-027: tester cannot release
    }

    public function test_failed_hygiene_discards_the_saleable_milk(): void
    {
        $this->close(['hygiene_ok' => false])->assertSessionHasNoErrors();
        $this->assertSame([0.0, 0], [MilkSession::sole()->saleable_l, MilkLot::count()]);
    }
}
