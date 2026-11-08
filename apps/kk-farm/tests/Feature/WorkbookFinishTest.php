<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthRecord;
use App\Models\LedgerEntry;
use App\Models\Medicine;
use App\Models\MedicineMovement;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\ActionCenter;
use App\Support\MedicineStock;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-078: expenses payment status, tasks, weight log and preventive care batches. */
class WorkbookFinishTest extends TestCase
{
    use RefreshDatabase;

    private Pen $pen;

    private Breed $breed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Finish Farm', 'slug' => 'finish-farm'])->id);
        $this->breed = Breed::create(['name' => 'Beetal', 'default_gestation_days' => 150]);
        $this->pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
    }

    private function goat(string $n): Animal
    {
        return Animal::create(['farm_number' => $n, 'sex' => 'female', 'breed_id' => $this->breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $this->pen->id]);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@finish.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_an_unpaid_expense_is_shown_until_it_is_marked_paid(): void
    {
        $goat = $this->goat('G-1');
        $this->actingAs($this->user('finance'))->post('/finance/entries', ['direction' => 'out', 'category' => 'Veterinary', 'amount' => 3500, 'counterparty' => 'Dr Khan', 'occurred_on' => now()->toDateString(), 'payment_status' => 'pending', 'reference' => 'INV-12', 'animal_id' => $goat->id])->assertSessionHasNoErrors();
        $entry = LedgerEntry::sole();
        $this->assertSame(['pending', 'INV-12', $goat->id], [$entry->payment_status, $entry->reference, $entry->animal_id]);
        $this->assertSame(['Dr Khan'], array_column(collect(ActionCenter::items($this->user('ceo')))->firstWhere('key', 'unpaid')['items'], 'text'));
        $this->actingAs($this->user('ceo'))->get('/finance')->assertInertia(fn (AssertableInertia $p) => $p->where('pending', 3500)->where('entries.0.payment', 'pending'));

        $this->actingAs($this->user('finance'))->post("/finance/entries/{$entry->id}/paid", ['paid_by' => 'Imran'])->assertSessionHasNoErrors();
        $this->assertSame(['paid', 'Imran', 3500.0], [$entry->fresh()->payment_status, $entry->fresh()->paid_by, (float) $entry->fresh()->amount]);
        $this->assertNull(collect(ActionCenter::items($this->user('ceo')))->firstWhere('key', 'unpaid'));
    }

    public function test_tasks_have_due_dates_goats_priorities_and_statuses(): void
    {
        $goat = $this->goat('G-1');
        $manager = $this->user('manager');
        $this->actingAs($manager)->post('/tasks', ['title' => 'Trim hooves', 'type' => 'health', 'user_id' => $manager->id, 'priority' => 'critical', 'due_on' => now()->addDays(3)->toDateString(), 'animal_id' => $goat->id])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post('/tasks', ['title' => 'Fix the gate', 'type' => 'maintenance', 'user_id' => $manager->id, 'priority' => 'low', 'due_on' => now()->subDays(2)->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post('/tasks', ['title' => 'X', 'type' => 'health', 'user_id' => $manager->id, 'priority' => 'urgent'])->assertSessionHasErrors('priority');

        $this->actingAs($manager)->get('/tasks')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('tasks.0.title', 'Trim hooves')->where('tasks.0.goat', 'G-1')->where('tasks.0.alert', 'soon')
            ->where('tasks.1.alert', 'overdue'));

        $gate = Task::where('title', 'Fix the gate')->sole();
        $this->actingAs($manager)->post("/tasks/{$gate->id}/status", ['status' => 'blocked'])->assertRedirect();
        $this->assertSame('blocked', $gate->fresh()->status);
        $this->assertSame(['Fix the gate'], array_column(collect(ActionCenter::items($manager))->firstWhere('key', 'tasks_overdue')['items'], 'text'), 'blocked is still unfinished');
        $this->actingAs($manager)->post("/tasks/{$gate->id}/status", ['status' => 'cancelled'])->assertRedirect();
        $this->assertNotNull($gate->fresh()->completed_at);
        $this->assertNull(collect(ActionCenter::items($manager))->firstWhere('key', 'tasks_overdue'));
    }

    public function test_the_weight_log_works_out_previous_weight_gain_and_daily_gain(): void
    {
        $goat = $this->goat('G-1');
        $care = $this->user('caretaker');
        $this->actingAs($care)->post('/weights', ['animal_id' => $goat->id, 'weight_kg' => 20, 'measured_on' => now()->subDays(10)->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($care)->post('/weights', ['animal_id' => $goat->id, 'weight_kg' => 22.5, 'measured_on' => now()->toDateString(), 'body_score' => 3, 'height_in' => 24])->assertSessionHasNoErrors();
        $this->actingAs($care)->post('/weights', ['animal_id' => $goat->id, 'weight_kg' => 22, 'body_score' => 7])->assertSessionHasErrors('body_score');

        $this->assertSame(3, WeightRecord::latest('id')->first()->body_score);
        $this->assertEquals(61.0, (float) $goat->fresh()->height_cm);
        $this->actingAs($care)->get('/weights')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('log.0.kg', 22.5)->where('log.0.previous', 20)->where('log.0.days', 10)->where('log.0.gain', 2.5)->where('log.0.adg', 250)->where('log.0.score', 3)
            ->where('log.1.previous', null));
    }

    public function test_preventive_care_for_a_batch_records_each_goat_and_issues_from_the_store(): void
    {
        $g1 = $this->goat('G-1');
        $g2 = $this->goat('G-2');
        $med = Medicine::create(['code' => 'IVM', 'name' => 'Ivermectin', 'category' => 'dewormer', 'unit' => 'ml', 'reorder_level' => 0, 'cost_per_unit_minor' => 800]);
        MedicineMovement::create(['medicine_id' => $med->id, 'moved_on' => now(), 'type' => 'purchase', 'qty' => 20, 'batch' => 'L1', 'expiry_on' => now()->addYear(), 'recorded_by' => 'x']);
        $vet = $this->user('vet');

        $this->actingAs($this->user('caretaker'))->post('/health/preventive', ['animal_ids' => [$g1->id], 'kind' => 'deworming', 'title' => 'X', 'given_on' => now()->toDateString(), 'care_status' => 'administered'])->assertForbidden();
        $this->actingAs($vet)->post('/health/preventive', ['animal_ids' => [$g1->id, $g2->id], 'kind' => 'deworming', 'medicine_id' => $med->id, 'qty_per_goat' => 2.5, 'given_on' => now()->toDateString(), 'next_due_on' => now()->addMonths(3)->toDateString(), 'batch_lot' => 'L1', 'care_status' => 'administered'])->assertSessionHasNoErrors();

        $records = HealthRecord::where('kind', 'deworming')->get();
        $this->assertCount(2, $records);
        $this->assertTrue($records->every(fn ($r) => $r->title === 'Ivermectin' && $r->batch_lot === 'L1' && $r->cost_minor === 2000 && $r->care_status === 'administered'));
        $this->assertEqualsWithDelta(15, MedicineStock::quantities()[$med->id], 0.001, '2 × 2.5 ml issued');

        $this->actingAs($vet)->post('/health/preventive', ['animal_ids' => [$g1->id], 'kind' => 'hoof_care', 'title' => 'Hoof trim', 'given_on' => now()->addDay()->toDateString(), 'care_status' => 'administered'])->assertSessionHasErrors('given_on');
        $this->actingAs($vet)->post('/health/preventive', ['animal_ids' => [$g1->id], 'kind' => 'hoof_care', 'title' => 'Hoof trim', 'given_on' => now()->addDays(5)->toDateString(), 'care_status' => 'planned'])->assertSessionHasNoErrors();
        $this->assertSame(now()->addDays(5)->toDateString(), HealthRecord::where('kind', 'hoof_care')->sole()->next_due_on->toDateString(), 'a planned job is due on its date');
    }
}
