<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\FatteningBatch;
use App\Models\FatteningBatchAnimal;
use App\Models\HealthCase;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Sale;
use App\Models\Shed;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Intake;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-187: App 18 along V3 flow 18, with BR-011 / BR-019 (withdrawal blocks sale), BR-026 (dispatch scan) and BR-020 (feed cost once). */
class FatteningTest extends TestCase
{
    use RefreshDatabase;

    private Pen $pen;

    private Pen $finish;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Fat Farm', 'slug' => 'fat-farm'])->id);
        $shed = Shed::create(['code' => 'S1', 'name' => 'KKF-FNU-01']);
        $this->pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Growers']);
        $this->finish = Pen::create(['shed_id' => $shed->id, 'name' => 'P02', 'purpose' => 'Finishing']);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@fat.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function goat(string $tag, float $kg): Animal
    {
        $g = Animal::create(['farm_number' => $tag, 'sex' => 'male', 'breed_id' => Breed::firstOrCreate(['name' => 'Beetal'])->id, 'birth_date' => now()->subMonths(8), 'origin' => 'purchased',
            'pen_id' => $this->pen->id, 'animal_purpose' => 'fattening', 'purchase_cost_minor' => 3000000]);
        WeightRecord::create(['animal_id' => $g->id, 'measured_on' => now()->subDays(30), 'weight_kg' => $kg, 'context' => 'registration', 'measured_by' => 'x']);

        return $g->fresh();
    }

    public function test_the_finishing_flow_from_selection_to_dispatch_and_margin(): void
    {
        $a = $this->goat('F-1', 30);
        $held = $this->goat('F-2', 28);
        Intake::open($held, $this->user('manager'));
        $b = $this->goat('F-3', 31);

        $this->actingAs($this->user('caretaker'))->post('/fattening/batches', [])->assertForbidden();
        $this->actingAs($this->user('manager'))->post('/fattening/batches', [
            'pen_id' => $this->finish->id, 'started_on' => now()->subDays(30)->toDateString(), 'target_weight_kg' => 40, 'target_days' => 60, 'animal_ids' => [$a->id, $held->id, $b->id],
        ])->assertSessionHasNoErrors();
        $batch = FatteningBatch::sole();
        $this->assertSame('FTN-'.now()->year.'-0001', $batch->code);
        $this->assertEqualsCanonicalizing(['F-1', 'F-3'], $batch->members->map(fn ($m) => $m->animal->farm_number)->all()); // F-2 held: intake quarantine
        $this->assertSame($this->finish->id, $a->fresh()->pen_id);

        $ma = FatteningBatchAnimal::where('animal_id', $a->id)->sole();
        $mb = FatteningBatchAnimal::where('animal_id', $b->id)->sole();
        // BR-018: an impossible reading is refused
        $this->actingAs($this->user('caretaker'))->post("/fattening/batches/{$batch->id}/weigh", ['measured_on' => now()->toDateString(), 'weights' => [['member_id' => $ma->id, 'kg' => 80]]])->assertSessionHasErrors('weights');
        $this->actingAs($this->user('caretaker'))->post("/fattening/batches/{$batch->id}/weigh", ['measured_on' => now()->toDateString(), 'weights' => [['member_id' => $ma->id, 'kg' => 36], ['member_id' => $mb->id, 'kg' => 36.5]]])->assertSessionHasNoErrors();
        WeightRecord::create(['animal_id' => $a->id, 'measured_on' => now(), 'weight_kg' => 41, 'context' => 'fattening', 'measured_by' => 'x']);
        WeightRecord::create(['animal_id' => $b->id, 'measured_on' => now(), 'weight_kg' => 41, 'context' => 'fattening', 'measured_by' => 'x']);

        // vet clearance: only the vet, refused during a meat withdrawal (BR-011 / BR-019)
        $case = HealthCase::create(['case_no' => 50, 'animal_id' => $b->id, 'onset_on' => now()->subDays(5), 'condition' => 'Fever', 'severity' => 'low', 'status' => 'recovered', 'vet' => 'x', 'recorded_by' => 'x']);
        Treatment::create(['health_case_id' => $case->id, 'animal_id' => $b->id, 'given_on' => now()->subDays(2), 'product' => 'Oxy', 'dose' => '5 ml', 'route' => 'injectable', 'meat_clear_on' => now()->addDays(10), 'recorded_by' => 'x']);
        $this->actingAs($this->user('manager'))->post("/fattening/members/{$ma->id}/clear")->assertForbidden();
        $this->actingAs($this->user('vet'))->post("/fattening/members/{$mb->id}/clear")->assertSessionHasErrors('member');
        $this->actingAs($this->user('vet'))->post("/fattening/members/{$ma->id}/clear")->assertSessionHasNoErrors();
        $this->assertSame('ready', $ma->fresh()->status);

        // dispatch: a wrong tag stops loading (BR-026); the right one sells once and posts the margin
        $this->actingAs($this->user('manager'))->post("/fattening/members/{$ma->id}/sell", ['scanned' => 'F-3', 'price' => 52000, 'sold_on' => now()->toDateString()])->assertSessionHasErrors('scanned');
        $this->actingAs($this->user('manager'))->post("/fattening/members/{$ma->id}/sell", ['scanned' => 'f-1', 'price' => 52000, 'exit_weight_kg' => 41, 'sold_on' => now()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $ma->refresh();
        $this->assertSame(['sold', 'left_farm'], [$ma->status, $a->fresh()->status]);
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, LedgerEntry::where('direction', 'in')->count());
        $this->assertNotNull($ma->cost_basis);

        $this->actingAs($this->user('manager'))->get('/fattening?tab=profit')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('batches.0.sold', 1)->where('batches.0.revenue', 5200000)->where('batches.0.members', fn ($m) => collect($m)->firstWhere('number', 'F-3')['meatHold'] !== null));
    }
}
