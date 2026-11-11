<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\FatteningBatch;
use App\Models\FatteningBatchAnimal;
use App\Models\FeedItem;
use App\Models\FeedUsage;
use App\Models\HealthCase;
use App\Models\MeatSelection;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Movements;
use App\Support\SaleCheck;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-197: journey H Fattening-to-Market (V3 flow 31): meat selection with approval, animal-level costing, any exit closes the batch record. */
class FatteningToMarketTest extends TestCase
{
    use RefreshDatabase;

    private Pen $pen;

    private Pen $finish;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Market Farm', 'slug' => 'market-farm'])->id);
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $this->pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->finish = Pen::create(['shed_id' => $shed->id, 'name' => 'P02', 'purpose' => 'Finishing']);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@market.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function goat(string $tag, string $sex, ?string $purpose, array $extra = []): Animal
    {
        $g = Animal::create($extra + ['farm_number' => $tag, 'sex' => $sex, 'breed_id' => Breed::firstOrCreate(['name' => 'Beetal'])->id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased',
            'pen_id' => $this->pen->id, 'animal_purpose' => $purpose, 'purchase_cost_minor' => 3000000]);
        WeightRecord::create(['animal_id' => $g->id, 'measured_on' => now()->subDays(20), 'weight_kg' => 30, 'context' => 'registration', 'measured_by' => 'x']);

        return $g->fresh();
    }

    public function test_a_breeder_cull_is_approved_finished_costed_and_closed_by_any_exit(): void
    {
        $doe = $this->goat('D-1', 'female', 'breeding');
        $pregnant = $this->goat('D-2', 'female', 'breeding', ['repro_status' => 'pregnant', 'pen_id' => $this->finish->id]);
        $feeder = $this->goat('F-1', 'male', 'fattening');
        $care = $this->user('caretaker');
        $manager = $this->user('manager');

        // Propose: a pregnant doe is refused; the barren doe is proposed by the caretaker.
        $this->actingAs($care)->post('/fattening/selections', ['animal_id' => $pregnant->id, 'reason' => 'cull_breeder'])->assertSessionHasErrors('animal_id');
        $this->actingAs($care)->post('/fattening/selections', ['animal_id' => $doe->id, 'reason' => 'cull_breeder', 'notes' => '3 failed matings'])->assertSessionHasNoErrors();
        $this->actingAs($care)->post('/fattening/selections', ['animal_id' => $doe->id, 'reason' => 'cull_breeder'])->assertSessionHasErrors('animal_id'); // already open
        $sel = MeatSelection::sole();

        // Breeding stock cannot join a batch before approval.
        $this->actingAs($manager)->post('/fattening/batches', ['pen_id' => $this->finish->id, 'started_on' => now()->subDays(10)->toDateString(), 'target_weight_kg' => 40, 'target_days' => 60,
            'animal_ids' => [$doe->id]])->assertSessionHasNoErrors();
        $this->assertSame(0, FatteningBatchAnimal::count());
        FatteningBatch::query()->delete();

        // Only the owner or manager decides, and never the proposer.
        $this->actingAs($care)->post("/fattening/selections/{$sel->id}/decide", ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($manager)->post("/fattening/selections/{$sel->id}/decide", ['decision' => 'rejected'])->assertSessionHasErrors('note');
        $this->actingAs($manager)->post("/fattening/selections/{$sel->id}/decide", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('fattening', $doe->fresh()->animal_purpose);
        $this->actingAs($manager)->post('/fattening/selections', ['animal_id' => $feeder->id, 'reason' => 'feeder'])->assertSessionHasNoErrors();
        $own = MeatSelection::where('animal_id', $feeder->id)->sole();
        $this->actingAs($manager)->post("/fattening/selections/{$own->id}/decide", ['decision' => 'approved'])->assertSessionHasErrors('decision');

        // Batch with both; the approved selection is marked batched.
        $this->actingAs($manager)->post('/fattening/batches', ['pen_id' => $this->finish->id, 'started_on' => now()->subDays(10)->toDateString(), 'target_weight_kg' => 40, 'target_days' => 60,
            'animal_ids' => [$doe->id, $feeder->id]])->assertSessionHasNoErrors();
        $batch = FatteningBatch::sole();
        $this->assertSame(2, $batch->members()->count());
        $this->assertSame(['batched', $batch->id], [$sel->fresh()->status, $sel->fresh()->batch_id]);

        // Feed actually fed in the pen they ate in since joining (both moved to finishing only today): 10 KG × PKR 100 a day for 5 days, shared by 2 goats = PKR 2,500 each.
        $wanda = FeedItem::create(['code' => 'WND', 'name' => 'Wanda', 'category' => 'concentrate', 'cost_per_kg_minor' => 10000]);
        foreach (range(1, 5) as $d) {
            FeedUsage::create(['id' => (string) Str::uuid(), 'fed_on' => now()->subDays($d)->toDateString(), 'meal' => 'morning', 'pen_id' => $this->pen->id, 'feed_item_id' => $wanda->id, 'headcount' => 2,
                'planned_kg' => 10, 'offered_kg' => 10, 'unit_cost_minor' => 10000, 'fed_at' => now(), 'fed_by' => 'x']);
        }
        // Health while finishing: a treatment of PKR 1,200; one from before it joined does not count.
        $case = HealthCase::create(['case_no' => 1, 'animal_id' => $feeder->id, 'onset_on' => now()->subDays(8), 'condition' => 'Cough', 'severity' => 'low', 'status' => 'recovered', 'recorded_by' => 'x']);
        Treatment::create(['health_case_id' => $case->id, 'animal_id' => $feeder->id, 'given_on' => now()->subDays(8), 'product' => 'Oxy', 'dose' => '5 ml', 'route' => 'injectable', 'cost_minor' => 120000, 'meat_clear_on' => now()->subDay(), 'recorded_by' => 'x']);
        Treatment::create(['health_case_id' => $case->id, 'animal_id' => $feeder->id, 'given_on' => now()->subDays(40), 'product' => 'Old', 'dose' => '1 ml', 'route' => 'oral', 'cost_minor' => 99900, 'recorded_by' => 'x']);
        WeightRecord::create(['animal_id' => $feeder->id, 'measured_on' => now(), 'weight_kg' => 38, 'context' => 'fattening', 'measured_by' => 'x']);

        $this->actingAs($manager)->get('/fattening?tab=profit')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('batches.0.feedBasis', 'actual')->where('batches.0.healthCost', 120000)->where('selections.0.status', 'batched'));
        $m = collect(\App\Support\Fattening::batch($batch->fresh())['members'])->firstWhere('number', 'F-1');
        $this->assertSame([250000, 'actual', 120000, 3000000 + 250000 + 120000], [$m['feedCost'], $m['feedBasis'], $m['healthCost'], $m['totalCost']]);
        $this->assertSame((int) round((250000 + 120000) / 8), $m['costPerKgGain']);

        // A batch on Sale Hold blocks its goats from sale orders and direct sales.
        $this->actingAs($manager)->post("/fattening/batches/{$batch->id}/plan", ['target_weight_kg' => 40, 'target_days' => 60, 'status' => 'sale_hold'])->assertSessionHasNoErrors();
        $this->assertContains("Finishing batch {$batch->code} on Sale Hold", SaleCheck::blockers($feeder->fresh()));
        try {
            Movements::exit($feeder->fresh(), 'sale', $manager, ['amount' => 60000]);
            $this->fail('A goat in a batch on Sale Hold was sold.');
        } catch (ValidationException) {
        }
        $batch->refresh()->update(['status' => 'active']);

        // A direct sale (not from the batch screen) still closes the batch record with revenue, costs and profit.
        Movements::exit($feeder->fresh(), 'sale', $manager, ['amount' => 60000, 'destination' => 'Rafiq Butcher']);
        $fm = FatteningBatchAnimal::where('animal_id', $feeder->id)->sole();
        $this->assertSame(['sold', 250000, 120000, 'actual'], [$fm->status, (int) $fm->feed_cost_minor, (int) $fm->health_cost_minor, $fm->feed_basis]);
        $this->assertNotNull($fm->sale_id);
        $row = collect(\App\Support\Fattening::batch($batch->fresh())['members'])->firstWhere('number', 'F-1');
        $this->assertSame([6000000, 6000000 - 3370000], [$row['revenue'], $row['profit']]);

        // A death closes the last record and the batch.
        Movements::exit($doe->fresh(), 'death', $manager, ['reason' => 'Bloat']);
        $this->assertSame('died', FatteningBatchAnimal::where('animal_id', $doe->id)->value('status'));
        $this->assertSame('closed', $batch->fresh()->status);
        $this->actingAs($manager)->get('/fattening')->assertInertia(fn (AssertableInertia $p) => $p->where('batches.0.returnPerHead', 6000000 - 3370000)->where('batches.0.sold', 1));
    }
}
