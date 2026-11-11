<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\EmergencyDrill;
use App\Models\FeedItem;
use App\Models\Medicine;
use App\Models\MedicineMovement;
use App\Models\MilkLot;
use App\Models\MilkSession;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\QualityCapa;
use App\Models\QualityCase;
use App\Models\QualityHold;
use App\Models\Shed;
use App\Models\Sop;
use App\Models\Task;
use App\Models\User;
use App\Support\MedicineStock;
use App\Support\Movements;
use App\Support\Quality;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-189: App 21 along V3 flow 21: held products cannot sell or be used; CAPA needs independent verification (BR-027). */
class QualityTest extends TestCase
{
    use RefreshDatabase;

    private Animal $goat;

    private MilkLot $lot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Quality Farm', 'slug' => 'quality-farm'])->id);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->goat = Animal::create(['farm_number' => 'Q-1', 'sex' => 'male', 'breed_id' => Breed::create(['name' => 'Beetal'])->id, 'birth_date' => now()->subYears(1), 'origin' => 'purchased', 'pen_id' => $pen->id, 'status' => 'active']);
        $s = MilkSession::create(['milked_on' => now()->toDateString(), 'session' => 'morning', 'hygiene_ok' => true, 'equipment_ok' => true, 'operator' => 'Ali',
            'produced_l' => 10, 'held_l' => 0, 'colostrum_l' => 0, 'kid_l' => 0, 'saleable_l' => 10, 'closed_by' => 'x']);
        $this->lot = MilkLot::create(['code' => 'MLK-TEST-001', 'session_id' => $s->id, 'litres' => 10, 'status' => 'stored', 'tested_by' => 'Test caretaker', 'tested_at' => now()]);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@quality.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function as(string $role)
    {
        return $this->actingAs($this->user($role));
    }

    public function test_a_milk_risk_is_held_investigated_capa_verified_independently_released_and_closed(): void
    {
        $this->as('caretaker')->post('/quality/cases', ['source' => 'milk', 'title' => 'Sour smell in tank', 'subject' => 'milk_lot', 'milk_lot_id' => $this->lot->id, 'severity' => 'high', 'immediate_risk' => true])
            ->assertSessionHasErrors('containment');
        $this->as('caretaker')->post('/quality/cases', ['source' => 'milk', 'title' => 'Sour smell in tank', 'subject' => 'milk_lot', 'milk_lot_id' => $this->lot->id, 'severity' => 'high', 'immediate_risk' => true, 'containment' => 'hold'])
            ->assertSessionHasNoErrors();
        $case = QualityCase::sole();
        $this->assertSame(['QC-0001', 'contained'], [$case->label(), $case->status]);
        $this->assertSame('hold', $this->lot->fresh()->status);
        $this->assertTrue(Task::where('title', 'like', 'Quality case QC-0001%')->exists());

        // Held milk cannot be released for dispatch, even after a retest.
        $this->as('vet')->post("/dairy/lots/{$this->lot->id}/test", ['appearance_ok' => true, 'smell_ok' => true, 'residue' => 'negative'])->assertSessionHasNoErrors();
        $this->as('vet')->post("/dairy/lots/{$this->lot->id}/release")->assertStatus(422);

        $this->as('manager')->post("/quality/cases/{$case->id}/capa", ['kind' => 'corrective', 'action' => 'x', 'owner_id' => $this->user('caretaker')->id, 'due_on' => now()->addDays(3)->toDateString()])
            ->assertSessionHasErrors('action'); // root cause first
        $this->as('manager')->post("/quality/cases/{$case->id}/investigate", ['root_cause' => 'Chiller off for 3 hours', 'evidence' => 'Temperature log 14 °C'])->assertSessionHasNoErrors();
        $this->as('manager')->post("/quality/cases/{$case->id}/capa", ['kind' => 'corrective', 'action' => 'Service the chiller', 'owner_id' => $this->user('caretaker')->id, 'due_on' => now()->addDays(3)->toDateString()])->assertSessionHasNoErrors();
        $capa = QualityCapa::sole();

        $this->as('manager')->post("/quality/capas/{$capa->id}/approve")->assertForbidden(); // the planner does not approve (BR-027)
        $this->as('vet')->post("/quality/capas/{$capa->id}/approve")->assertSessionHasNoErrors();
        $this->as('caretaker')->post("/quality/capas/{$capa->id}/execute", ['notes' => 'Compressor relay replaced'])->assertSessionHasNoErrors();
        $this->assertSame('verifying', $case->fresh()->status);

        // Fail → reopen / escalate → replan.
        $this->as('vet')->post("/quality/capas/{$capa->id}/verify", ['pass' => false, 'notes' => 'Still 9 °C after an hour'])->assertSessionHasNoErrors();
        $this->assertSame('reopened', $case->fresh()->status);
        $this->as('manager')->post("/quality/holds/".QualityHold::sole()->id.'/release', ['outcome' => 'released', 'note' => 'ok'])->assertStatus(422); // not verified yet
        $this->as('manager')->post("/quality/cases/{$case->id}/capa", ['kind' => 'corrective', 'action' => 'Replace the chiller thermostat', 'owner_id' => $this->user('manager')->id, 'due_on' => now()->addDays(2)->toDateString(), 'replaces_id' => $capa->id])->assertSessionHasNoErrors();
        $again = QualityCapa::latest('id')->first();
        $this->as('vet')->post("/quality/capas/{$again->id}/approve")->assertSessionHasNoErrors();
        $this->as('manager')->post("/quality/capas/{$again->id}/execute", ['notes' => 'Thermostat replaced'])->assertSessionHasNoErrors();
        $this->as('manager')->post("/quality/capas/{$again->id}/verify", ['pass' => true, 'notes' => 'x'])->assertForbidden(); // independent verification
        $this->as('vet')->post("/quality/capas/{$again->id}/verify", ['pass' => true, 'notes' => '3 °C for 24 hours'])->assertSessionHasNoErrors();
        $this->assertSame('verified', $case->fresh()->status);

        $this->as('vet')->post("/quality/cases/{$case->id}/close", ['learning' => 'x'])->assertStatus(422); // hold still active
        $this->as('caretaker')->post('/quality/holds/'.QualityHold::sole()->id.'/release', ['outcome' => 'released', 'note' => 'x'])->assertForbidden();
        $this->as('manager')->post('/quality/holds/'.QualityHold::sole()->id.'/release', ['outcome' => 'released', 'note' => 'Chiller verified'])->assertSessionHasNoErrors();
        $this->as('vet')->post("/quality/cases/{$case->id}/close", ['learning' => 'Daily chiller temperature check added to the evening checklist'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $case->fresh()->status);
        $this->as('manager')->post("/dairy/lots/{$this->lot->id}/release")->assertSessionHasNoErrors(); // the retested lot can now go
    }

    public function test_held_goats_cannot_be_sold_and_held_feed_and_medicine_batches_are_not_used(): void
    {
        $this->as('vet')->post('/quality/cases', ['source' => 'outbreak', 'title' => 'Suspected CCPP', 'subject' => 'animal', 'animal_id' => $this->goat->id, 'severity' => 'critical', 'immediate_risk' => true, 'containment' => 'isolate'])->assertSessionHasNoErrors();
        $this->assertTrue(Task::where('title', 'like', 'Isolate Q-1%')->exists());
        try {
            Movements::exit($this->goat, 'sale', $this->user('ceo'), ['amount' => 50000]);
            $this->fail('A held goat was sold.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('quality isolate', $e->getMessage());
        }

        $feed = FeedItem::create(['code' => 'F1', 'name' => 'Maize Grain', 'category' => 'grain']);
        $this->as('manager')->post('/quality/cases', ['source' => 'feed', 'title' => 'Mould', 'subject' => 'feed_item', 'feed_item_id' => $feed->id, 'severity' => 'high', 'immediate_risk' => true, 'containment' => 'hold'])->assertSessionHasNoErrors();
        $this->expectFeedBlocked($feed->id);

        $med = Medicine::create(['code' => 'M1', 'name' => 'Oxytet', 'category' => 'antibiotic', 'unit' => 'ml']);
        MedicineMovement::create(['medicine_id' => $med->id, 'moved_on' => now(), 'type' => 'opening', 'qty' => 50, 'batch' => 'BAD1', 'expiry_on' => now()->addYear(), 'recorded_by' => 'x']);
        MedicineMovement::create(['medicine_id' => $med->id, 'moved_on' => now(), 'type' => 'opening', 'qty' => 50, 'batch' => 'GOOD2', 'expiry_on' => now()->addYears(2), 'recorded_by' => 'x']);
        $this->as('vet')->post('/quality/cases', ['source' => 'medicine', 'title' => 'Cloudy vials', 'subject' => 'medicine', 'medicine_id' => $med->id, 'batch' => 'BAD1', 'severity' => 'high', 'immediate_risk' => true, 'containment' => 'hold'])->assertSessionHasNoErrors();
        MedicineStock::issue($med, 5, ['moved_on' => now(), 'recorded_by' => 'x']);
        $this->assertSame('GOOD2', MedicineMovement::where('type', 'issue')->sole()->batch); // FEFO skips the held batch

        $this->as('vet')->get('/quality?tab=holds')->assertInertia(fn (AssertableInertia $p) => $p->component('Quality')->has('holds', 3)->where('kpi.activeHolds', 3));
    }

    private function expectFeedBlocked(int $feedId): void
    {
        try {
            Quality::assertFeedNotHeld([$feedId]);
            $this->fail('Held feed was allowed.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('quality hold', $e->getMessage());
        }
    }

    public function test_sop_revisions_need_another_approver_and_a_failed_drill_opens_a_case(): void
    {
        $this->as('manager')->post('/quality/sops', ['code' => 'sop-mlk-01', 'title' => 'Milking hygiene', 'area' => 'milking', 'body' => 'Wash udders. Strip foremilk.'])->assertSessionHasNoErrors();
        $sop = Sop::sole();
        $rev = $sop->revisions()->sole();
        $this->as('manager')->post("/quality/sop-revisions/{$rev->id}/approve")->assertForbidden();
        $this->as('vet')->post("/quality/sop-revisions/{$rev->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame([1, 'SOP-MLK-01'], [$sop->fresh()->version, $sop->code]);
        $this->as('vet')->post("/quality/sops/{$sop->id}/revise", ['body' => 'Wash, dry and dip teats.', 'change_note' => 'Teat dip added'])->assertSessionHasNoErrors();
        $this->assertSame(2, $sop->revisions()->count()); // append-only: version 1 is kept
        $this->assertSame(1, $sop->fresh()->version);

        $this->as('manager')->post('/quality/drills', ['scenario' => 'fire', 'held_on' => now()->toDateString(), 'participants' => 6, 'passed' => false])->assertSessionHasErrors('findings');
        $this->as('manager')->post('/quality/drills', ['scenario' => 'fire', 'held_on' => now()->toDateString(), 'participants' => 6, 'response_minutes' => 12, 'passed' => false, 'findings' => 'Extinguisher in Shed 2 empty'])->assertSessionHasNoErrors();
        $drill = EmergencyDrill::sole();
        $this->assertSame('Failed drill: Fire', QualityCase::find($drill->case_id)->title);
        $this->assertSame(now()->addMonths(6)->toDateString(), $drill->next_due_on->toDateString());

        $this->as('manager')->post('/quality/register', ['kind' => 'licence', 'name' => 'Dairy licence', 'expires_on' => now()->addDays(10)->toDateString()])->assertSessionHasNoErrors();
        $this->as('caretaker')->get('/quality')->assertInertia(fn (AssertableInertia $p) => $p->where('kpi.registerDue', 1)->where('isLead', false));
    }
}
