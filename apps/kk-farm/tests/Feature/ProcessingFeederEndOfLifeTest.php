<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\AnimalPurchaseLot;
use App\Models\Breed;
use App\Models\EndOfLifeReview;
use App\Models\MeatSelection;
use App\Models\MilkDispatch;
use App\Models\MilkLot;
use App\Models\MilkProcessingBatch;
use App\Models\MilkSession;
use App\Models\Organization;
use App\Models\User;
use App\Support\Intake;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-211: milk processing batches, feeder purchase lots, humane end-of-life review. */
class ProcessingFeederEndOfLifeTest extends TestCase
{
    use RefreshDatabase;

    private Breed $breed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Life Farm', 'slug' => 'life-farm'])->id);
        $this->breed = Breed::create(['name' => 'Beetal']);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@life.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function goat(string $tag, array $extra = []): Animal
    {
        return Animal::create($extra + ['farm_number' => $tag, 'sex' => 'male', 'breed_id' => $this->breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'status' => 'active']);
    }

    public function test_processed_milk_traces_to_its_lot_and_is_released_by_someone_else(): void
    {
        $s = MilkSession::create(['milked_on' => now()->toDateString(), 'session' => 'morning', 'hygiene_ok' => true, 'equipment_ok' => true, 'operator' => 'Ali', 'produced_l' => 20, 'held_l' => 0, 'colostrum_l' => 0, 'kid_l' => 0, 'discard_l' => 0, 'saleable_l' => 20, 'closed_by' => 'x']);
        $lot = MilkLot::create(['code' => 'MLK-P-001', 'session_id' => $s->id, 'litres' => 20, 'status' => 'released', 'tested_by' => 'a', 'released_by' => 'b']);
        $d = MilkDispatch::create(['lot_id' => $lot->id, 'dispatched_on' => now()->toDateString(), 'kind' => 'processing', 'litres' => 20, 'recorded_by' => 'x']);
        $make = fn ($x) => $this->actingAs($this->user('caretaker'))->post('/dairy/processing', $x + ['dispatch_id' => $d->id, 'product' => 'yogurt', 'made_on' => now()->toDateString()]);
        $make(['input_l' => 15, 'output_qty' => 14.2])->assertSessionHasNoErrors();
        $make(['input_l' => 10, 'output_qty' => 9])->assertSessionHasErrors('input_l'); // only 5 L left
        $b = MilkProcessingBatch::sole();
        $this->assertSame('MLK-P-001', $b->dispatch->lot->code);

        $decide = fn ($role) => $this->actingAs($this->user($role))->post("/dairy/processing/{$b->id}/decide", ['decision' => 'released']);
        $decide('caretaker')->assertForbidden();
        $decide('vet')->assertSessionHasErrors('decision'); // heat treatment not recorded
        $b->update(['heat_temp_c' => 85, 'heat_minutes' => 30]);
        $decide('vet')->assertSessionHasNoErrors();
        $this->assertSame('released', $b->fresh()->status);
        $this->actingAs($this->user('manager'))->get('/dairy/processing')->assertOk();
    }

    public function test_a_feeder_lot_goat_is_proposed_for_finishing_when_cleared(): void
    {
        $lot = AnimalPurchaseLot::create(['lot_no' => 'APL-T-0001', 'source' => 'Bakra Mandi', 'expected_head' => 1, 'budget_minor' => 5000000, 'status' => 'received', 'requested_by' => 'x', 'kind' => 'feeder']);
        $g = $this->goat('F-1', ['purchase_lot_id' => $lot->id]);
        $case = Intake::open($g, $this->user('manager'));
        $this->actingAs($this->user('vet'))->post("/intake/{$case->id}/clear", ['notes' => 'Healthy'])->assertSessionHasNoErrors();
        $sel = MeatSelection::sole();
        $this->assertSame(['feeder', 'proposed', $g->id], [$sel->reason, $sel->status, $sel->animal_id]);
    }

    public function test_end_of_life_needs_the_vet_and_a_separate_authoriser_and_closes_the_record(): void
    {
        $g = $this->goat('E-1');
        $this->actingAs($this->user('caretaker'))->post('/welfare/end-of-life', ['animal_id' => $g->id, 'reason' => 'severe_injury', 'details' => 'Broken leg, cannot stand, in pain since yesterday'])->assertSessionHasNoErrors();
        $r = EndOfLifeReview::sole();
        $this->actingAs($this->user('manager'))->post("/welfare/end-of-life/{$r->id}/assess", ['recommends' => true, 'assessment' => 'Compound fracture'])->assertForbidden();
        $this->actingAs($this->user('manager'))->post("/welfare/end-of-life/{$r->id}/authorise", ['decision' => 'approved'])->assertStatus(422); // vet first
        $this->actingAs($this->user('vet'))->post("/welfare/end-of-life/{$r->id}/assess", ['recommends' => true, 'assessment' => 'Compound fracture, no realistic repair; pain not controlled'])->assertSessionHasNoErrors();
        $this->actingAs($this->user('caretaker'))->post("/welfare/end-of-life/{$r->id}/authorise", ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->user('manager'))->post("/welfare/end-of-life/{$r->id}/authorise", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->actingAs($this->user('manager'))->post("/welfare/end-of-life/{$r->id}/perform", ['method' => 'x', 'disposition' => 'y'])->assertForbidden(); // only the vet
        $this->actingAs($this->user('vet'))->post("/welfare/end-of-life/{$r->id}/perform", ['method' => 'Sedation then pentobarbital IV', 'disposition' => 'Burial pit'])->assertSessionHasNoErrors();
        $this->assertSame(['done', 'deceased'], [$r->fresh()->status, $g->fresh()->status]);
        $this->assertNotNull($r->fresh()->movement_id);
        $this->actingAs($this->user('manager'))->get('/welfare/end-of-life')->assertOk();
    }
}
