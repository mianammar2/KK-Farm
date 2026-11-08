<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\FeedItem;
use App\Models\HealthCase;
use App\Models\HealthRecord;
use App\Models\Mating;
use App\Models\Medicine;
use App\Models\MedicineMovement;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\Treatment;
use App\Models\User;
use App\Support\ActionCenter;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-077: the Action Center finds the workbook's exceptions, per role. */
class ActionCenterTest extends TestCase
{
    use RefreshDatabase;

    private Breed $breed;

    private Pen $pen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Action Farm', 'slug' => 'action-farm'])->id);
        $this->breed = Breed::create(['name' => 'Beetal', 'default_gestation_days' => 150]);
        $this->pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
    }

    private function goat(string $n, array $extra = []): Animal
    {
        return Animal::create($extra + ['farm_number' => $n, 'sex' => 'female', 'breed_id' => $this->breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $this->pen->id]);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@action.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    /** @return array<string, array> items by key */
    private function items(string $role): array
    {
        return collect(ActionCenter::items($this->user($role)))->keyBy('key')->all();
    }

    public function test_it_finds_health_breeding_store_data_and_task_exceptions(): void
    {
        $sick = $this->goat('SICK-1');
        $case = HealthCase::create(['case_no' => 1, 'animal_id' => $sick->id, 'onset_on' => now()->subDays(5), 'condition' => 'Pneumonia', 'severity' => 'high', 'status' => 'under_treatment', 'recorded_by' => 'x']);
        Treatment::create(['health_case_id' => $case->id, 'animal_id' => $sick->id, 'given_on' => now()->subDays(4), 'product' => 'Oxy', 'dose' => '5 ml', 'route' => 'injectable', 'next_dose_on' => now()->subDay(), 'meat_clear_on' => now()->addDays(20), 'recorded_by' => 'x']);

        $vacc = $this->goat('VAC-1');
        HealthRecord::create(['animal_id' => $vacc->id, 'kind' => 'vaccination', 'occurred_on' => now()->subYear(), 'title' => 'PPR', 'recorded_by' => 'x', 'recorded_role' => 'Veterinarian', 'next_due_on' => now()->subDays(10)]);
        $redone = $this->goat('VAC-2');
        HealthRecord::create(['animal_id' => $redone->id, 'kind' => 'vaccination', 'occurred_on' => now()->subYear(), 'title' => 'PPR', 'recorded_by' => 'x', 'recorded_role' => 'Veterinarian', 'next_due_on' => now()->subDays(10)]);
        HealthRecord::create(['animal_id' => $redone->id, 'kind' => 'vaccination', 'occurred_on' => now()->subDays(9), 'title' => 'PPR', 'recorded_by' => 'x', 'recorded_role' => 'Veterinarian', 'next_due_on' => now()->addYear()]);

        $doe = $this->goat('DOE-1');
        Mating::create(['dam_id' => $doe->id, 'mated_on' => now()->subDays(40), 'method' => 'natural', 'expected_kidding_on' => now()->addDays(110), 'check_due_on' => now()->subDays(10), 'status' => 'open', 'recorded_by' => 'x']);
        $late = $this->goat('DOE-2');
        Mating::create(['dam_id' => $late->id, 'mated_on' => now()->subDays(155), 'method' => 'natural', 'expected_kidding_on' => now()->subDays(5), 'status' => 'pregnant', 'recorded_by' => 'x']);
        $this->goat('KID-1', ['origin' => 'born_on_farm', 'birth_date' => now()->subDays(90)]);
        $this->goat('LOOSE-1', ['pen_id' => null]);

        $med = Medicine::create(['code' => 'OLD', 'name' => 'Old vial', 'category' => 'other', 'unit' => 'ml', 'reorder_level' => 0]);
        MedicineMovement::create(['medicine_id' => $med->id, 'moved_on' => now()->subYear(), 'type' => 'purchase', 'qty' => 10, 'batch' => 'B1', 'expiry_on' => now()->subMonth(), 'recorded_by' => 'x']);
        FeedItem::create(['code' => 'WS', 'name' => 'Straw', 'category' => 'roughage', 'reorder_level_kg' => 50]);
        Task::create(['title' => 'Fix the gate', 'type' => 'other', 'due_on' => now()->subDays(2), 'priority' => 'normal', 'status' => 'open']);

        $items = $this->items('ceo');
        $this->assertSame(['SICK-1'], array_column($items['dose_overdue']['items'], 'text'));
        $this->assertSame('critical', $items['open_cases']['severity'], 'a high-severity case is urgent');
        $this->assertSame(['VAC-1'], array_column($items['care_overdue']['items'], 'text'), 'a vaccine redone since is not overdue');
        $this->assertSame(['SICK-1'], array_column($items['withdrawal']['items'], 'text'));
        $this->assertSame(['DOE-1'], array_column($items['check_overdue']['items'], 'text'));
        $this->assertSame(['DOE-2'], array_column($items['edd_passed']['items'], 'text'));
        $this->assertSame(['KID-1'], array_column($items['weaning']['items'], 'text'));
        $this->assertSame(['Old vial'], array_column($items['medicine']['items'], 'text'));
        $this->assertSame(['Straw'], array_column($items['feed_low']['items'], 'text'));
        $this->assertContains('LOOSE-1', array_column($items['missing']['items'], 'text'));
        $this->assertSame(['Fix the gate'], array_column($items['tasks_overdue']['items'], 'text'));
        $this->assertSame('critical', ActionCenter::items($this->user('ceo'))[0]['severity'], 'most urgent first');
    }

    public function test_each_role_sees_only_the_modules_it_can_open(): void
    {
        $sick = $this->goat('SICK-1');
        HealthCase::create(['case_no' => 1, 'animal_id' => $sick->id, 'onset_on' => now(), 'condition' => 'X', 'severity' => 'low', 'recorded_by' => 'x']);
        Task::create(['title' => 'Late task', 'type' => 'other', 'due_on' => now()->subDay(), 'priority' => 'normal', 'status' => 'open']);

        $finance = $this->items('finance');
        $this->assertArrayNotHasKey('open_cases', $finance);
        $this->assertArrayHasKey('tasks_overdue', $finance);
        $this->assertArrayHasKey('open_cases', $this->items('vet'));
    }

    public function test_the_page_and_the_dashboard_banner_show_the_same_items(): void
    {
        Task::create(['title' => 'Late task', 'type' => 'other', 'due_on' => now()->subDay(), 'priority' => 'normal', 'status' => 'open']);
        $this->actingAs($this->user('manager'))->get('/actions')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Actions')->where('items', fn ($i) => collect($i)->contains('key', 'tasks_overdue')));
        $this->actingAs($this->user('manager'))->get('/')->assertInertia(fn (AssertableInertia $p) => $p->where('actions', fn ($a) => collect($a)->contains(fn ($x) => $x['key'] === 'tasks_overdue' && $x['count'] === 1)));
    }
}
