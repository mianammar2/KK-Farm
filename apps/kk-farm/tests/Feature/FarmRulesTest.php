<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\AnimalEvent;
use App\Models\AnimalPhoto;
use App\Models\Breed;
use App\Models\Department;
use App\Models\FeedingGroup;
use App\Models\FeedingLog;
use App\Models\HealthRecord;
use App\Models\LedgerEntry;
use App\Models\Mating;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RfidAssignment;
use App\Models\RfidTag;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Farm;
use App\Support\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Protects the critical business rules listed in docs/TESTING_STRATEGY.md (R-1 to R-12). */
class FarmRulesTest extends TestCase
{
    use RefreshDatabase;

    private Breed $breed;

    private Pen $pen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Test Farm', 'slug' => 'test-farm'])->id);
        $this->breed = Breed::create(['name' => 'Test Breed', 'default_gestation_days' => 150]);
        $shed = Shed::create(['code' => 'SHED-T', 'name' => 'Test Shed']);
        $this->pen = Pen::create(['shed_id' => $shed->id, 'name' => 'Pen T', 'purpose' => 'Kids']);
    }

    private function user(string $role): User
    {
        return User::create(['name' => "Test $role", 'email' => "$role@test.local", 'password' => 'unused', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function goat(string $number, string $sex = 'female'): Animal
    {
        return Animal::create(['farm_number' => $number, 'sex' => $sex, 'breed_id' => $this->breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $this->pen->id])->refresh();
    }

    private function tag(string $code = 'SIM-1'): RfidTag
    {
        return RfidTag::create(['tag_code' => $code]);
    }

    public function test_reassigning_a_tag_never_transfers_animal_history(): void
    {
        $manager = $this->user('manager');
        $first = $this->goat('T-0001');
        $second = $this->goat('T-0002');
        $tag = $this->tag();

        Farm::assignTag($first, $tag, 'initial', $manager);
        WeightRecord::create(['animal_id' => $first->id, 'measured_on' => now(), 'weight_kg' => 30, 'measured_by' => 'x']);
        HealthRecord::create(['animal_id' => $first->id, 'kind' => 'examination', 'occurred_on' => now(), 'title' => 'Check', 'recorded_by' => 'x', 'recorded_role' => 'Veterinarian']);

        Farm::closeOpenTag($first, 'removed', $manager);
        Farm::assignTag($second, $tag->fresh(), 'reassigned', $manager);

        $this->assertSame(1, WeightRecord::where('animal_id', $first->id)->count());
        $this->assertSame(1, HealthRecord::where('animal_id', $first->id)->count());
        $this->assertSame(0, WeightRecord::where('animal_id', $second->id)->count());
        $this->assertSame(0, HealthRecord::where('animal_id', $second->id)->count());
        $this->assertSame($second->id, RfidAssignment::where('rfid_tag_id', $tag->id)->whereNull('valid_to')->value('animal_id'));
        $this->assertSame(2, RfidAssignment::where('rfid_tag_id', $tag->id)->count());
    }

    public function test_a_tag_in_use_cannot_be_given_to_another_goat(): void
    {
        $manager = $this->user('manager');
        $tag = $this->tag();
        Farm::assignTag($this->goat('T-0001'), $tag, 'initial', $manager);

        $this->expectException(ValidationException::class);
        Farm::assignTag($this->goat('T-0002'), $tag->fresh(), 'reassigned', $manager);
    }

    public function test_the_database_itself_refuses_two_open_assignments_for_one_tag(): void
    {
        $tag = $this->tag();
        $row = ['rfid_tag_id' => $tag->id, 'valid_from' => now(), 'reason_assigned' => 'initial', 'assigned_by' => 'x'];
        RfidAssignment::create($row + ['animal_id' => $this->goat('T-0001')->id]);

        $this->expectException(QueryException::class);
        RfidAssignment::create($row + ['animal_id' => $this->goat('T-0002')->id]);
    }

    public function test_farm_number_can_never_be_reused(): void
    {
        $this->goat('T-0001');

        $this->expectException(QueryException::class);
        $this->goat('T-0001');
    }

    public function test_leaving_the_farm_releases_the_tag_and_keeps_every_record(): void
    {
        $manager = $this->user('manager');
        $goat = $this->goat('T-0001');
        $tag = $this->tag();
        Farm::assignTag($goat, $tag, 'initial', $manager);
        WeightRecord::create(['animal_id' => $goat->id, 'measured_on' => now(), 'weight_kg' => 30, 'measured_by' => 'x']);

        $this->actingAs($manager)->post("/goats/{$goat->id}/exit", ['reason' => 'sold'])->assertRedirect();

        $goat->refresh();
        $this->assertSame('left_farm', $goat->status);
        $this->assertSame('in_stock', $tag->fresh()->status);
        $this->assertSame(1, WeightRecord::where('animal_id', $goat->id)->count());
        $this->assertTrue(AnimalEvent::where('animal_id', $goat->id)->where('type', 'exit')->exists());
        $this->actingAs($manager)->get("/goats/{$goat->id}")->assertOk();
    }

    public function test_employees_without_finance_access_are_refused(): void
    {
        foreach (['manager', 'vet', 'caretaker', 'feeding', 'cleaning'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get('/finance')->assertForbidden();
            $this->actingAs($user)->post('/finance/entries', ['direction' => 'out', 'category' => 'Feed', 'amount' => 100, 'occurred_on' => now()->toDateString()])->assertForbidden();
        }
        $this->assertSame(0, LedgerEntry::count());

        $this->actingAs($this->user('finance'))->get('/finance')->assertOk();
        $this->actingAs($this->user('ceo'))->get('/finance')->assertOk();
    }

    public function test_dashboard_sends_no_finance_figures_to_other_roles(): void
    {
        $this->actingAs($this->user('caretaker'))->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard')->where('finance', null));
    }

    public function test_only_a_veterinarian_can_record_clinical_actions(): void
    {
        $goat = $this->goat('T-0001');
        $payload = ['animal_id' => $goat->id, 'kind' => 'treatment', 'title' => 'Antibiotic course'];

        foreach (['ceo', 'manager', 'caretaker', 'feeding', 'finance'] as $role) {
            $this->actingAs($this->user($role))->post('/health/clinical', $payload)->assertForbidden();
        }
        $this->assertSame(0, HealthRecord::count());

        $vet = $this->user('vet');
        $this->actingAs($vet)->post('/health/clinical', $payload)->assertRedirect();
        $this->assertSame($vet->name, HealthRecord::first()->recorded_by);
    }

    public function test_a_caretaker_can_report_a_concern_but_not_close_a_case(): void
    {
        $goat = $this->goat('T-0001');
        $caretaker = $this->user('caretaker');

        $this->actingAs($caretaker)->post('/health/observations', ['animal_id' => $goat->id, 'title' => 'Limping', 'severity' => 'low'])->assertRedirect();
        $this->assertSame('watch', $goat->fresh()->health_status);

        $this->actingAs($caretaker)->post('/health/records/'.HealthRecord::first()->id.'/close')->assertForbidden();
    }

    // Ration approval by a veterinarian (R-6) is covered by FeedTest::test_rations_need_meals_totalling_100_and_a_vet_to_approve (D-054).

    public function test_finance_corrections_are_reversing_entries_and_never_edits(): void
    {
        $entry = LedgerEntry::create(['direction' => 'out', 'category' => 'Feed', 'amount' => 5000, 'occurred_on' => now(), 'recorded_by' => 'x']);
        $finance = $this->user('finance');

        $this->actingAs($finance)->post("/finance/entries/{$entry->id}/reverse")->assertRedirect();

        $this->assertSame(5000.0, $entry->fresh()->amount);
        $this->assertSame(2, LedgerEntry::count());
        $reversal = LedgerEntry::where('reverses_id', $entry->id)->first();
        $this->assertSame('in', $reversal->direction);
        $this->assertSame(5000.0, $reversal->amount);

        $this->actingAs($finance)->post("/finance/entries/{$entry->id}/reverse")->assertStatus(422);
        // No edit or delete route exists at all for ledger entries.
        $this->actingAs($finance)->put("/finance/entries/{$entry->id}", ['amount' => 1])->assertNotFound();
        $this->actingAs($finance)->delete("/finance/entries/{$entry->id}")->assertNotFound();
        $this->assertSame(5000.0, $entry->fresh()->amount);
    }

    public function test_issued_farm_numbers_never_repeat_even_after_a_goat_leaves(): void
    {
        $first = Farm::nextFarmNumber();
        $second = Farm::nextFarmNumber();

        $this->assertNotSame($first, $second);
        $this->assertGreaterThan((int) substr($first, 5), (int) substr($second, 5));
    }

    public function test_recording_a_kidding_registers_kids_linked_to_both_parents(): void
    {
        $dam = $this->goat('T-0001');
        $sire = $this->goat('T-0002', 'male');
        $mating = Mating::create(['dam_id' => $dam->id, 'sire_id' => $sire->id, 'mated_on' => now()->subDays(150), 'status' => 'pregnant', 'recorded_by' => 'x']);

        $this->actingAs($this->user('vet'))->post("/breeding/matings/{$mating->id}/kidding", ['alive' => 2, 'stillborn' => 0, 'females' => 1])->assertRedirect();

        $kids = Animal::where('dam_id', $dam->id)->get();
        $this->assertCount(2, $kids);
        $this->assertTrue($kids->every(fn (Animal $kid) => $kid->sire_id === $sire->id));
        $this->assertSame(1, $kids->where('sex', 'female')->count());
        $this->assertSame('kidded', $mating->fresh()->status);
    }

    public function test_relatedness_check_finds_shared_recorded_ancestors(): void
    {
        $parent = $this->goat('T-0001');
        $son = Animal::create(['farm_number' => 'T-0002', 'sex' => 'male', 'breed_id' => $this->breed->id, 'origin' => 'born_on_farm', 'dam_id' => $parent->id])->refresh();
        $daughter = Animal::create(['farm_number' => 'T-0003', 'sex' => 'female', 'breed_id' => $this->breed->id, 'origin' => 'born_on_farm', 'dam_id' => $parent->id])->refresh();

        $this->assertContains($parent->id, Farm::sharedAncestors($son, $daughter));
        $this->assertEmpty(Farm::sharedAncestors($this->goat('T-0004'), $this->goat('T-0005', 'male')));
    }

    public function test_photos_stay_with_the_goat_when_its_tag_is_reassigned(): void
    {
        Storage::fake();
        $manager = $this->user('manager');
        $first = $this->goat('T-0001');
        $second = $this->goat('T-0002');
        $tag = $this->tag();
        Farm::assignTag($first, $tag, 'initial', $manager);

        $this->actingAs($manager)->post("/goats/{$first->id}/photos", ['photo' => UploadedFile::fake()->image('goat.jpg')])->assertRedirect();

        Farm::closeOpenTag($first, 'removed', $manager);
        Farm::assignTag($second, $tag->fresh(), 'reassigned', $manager);

        $this->assertSame(1, AnimalPhoto::where('animal_id', $first->id)->count());
        $this->assertSame(0, AnimalPhoto::where('animal_id', $second->id)->count());
        $this->assertTrue(AnimalPhoto::first()->is_profile);
    }

    public function test_a_rejected_upload_leaves_no_broken_photo_record(): void
    {
        Storage::fake();
        $goat = $this->goat('T-0001');

        $this->actingAs($this->user('manager'))->post("/goats/{$goat->id}/photos", ['photo' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('photo');

        $this->assertSame(0, AnimalPhoto::count());
        $this->assertEmpty(Storage::allFiles());
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/finance')->assertRedirect('/login');
    }

    public function test_a_manager_adds_and_renames_sheds_and_pens(): void
    {
        $manager = $this->user('manager');

        $this->actingAs($manager)->post('/sheds', ['name' => 'Kid shed', 'status' => 'active'])->assertRedirect()->assertSessionHasNoErrors();
        $shed = Shed::where('name', 'Kid shed')->firstOrFail();
        $this->assertSame('SHED-2', $shed->code);
        $this->assertSame(Tenant::current(), $shed->org_id);

        $this->actingAs($manager)->post("/sheds/{$shed->id}/pens", ['name' => 'Pen A', 'purpose' => 'Kids', 'capacity' => 40])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/sheds/{$shed->id}/pens", ['name' => 'Pen A', 'purpose' => 'Weaners'])->assertSessionHasErrors('name');
        $pen = $shed->pens()->firstOrFail();
        $this->assertSame(40, $pen->capacity);

        $this->actingAs($manager)->patch("/pens/{$pen->id}", ['name' => 'Pen A1', 'purpose' => 'Weaners', 'capacity' => null])->assertSessionHasNoErrors();
        $this->assertSame(['Pen A1', 'Weaners', null], [$pen->fresh()->name, $pen->fresh()->purpose, $pen->fresh()->capacity]);

        $this->actingAs($manager)->post('/sheds', ['name' => 'Kid shed', 'status' => 'active'])->assertSessionHasErrors('name');
    }

    public function test_pen_capacity_and_shed_status_respect_goats_already_housed(): void
    {
        $manager = $this->user('manager');
        $this->goat('T-0101');
        $this->goat('T-0102');

        $this->actingAs($manager)->patch("/pens/{$this->pen->id}", ['name' => 'Pen T', 'purpose' => 'Kids', 'capacity' => 1])->assertSessionHasErrors('capacity');
        $this->actingAs($manager)->patch("/sheds/{$this->pen->shed_id}", ['name' => 'Test Shed', 'status' => 'planned'])->assertSessionHasErrors('status');
    }

    public function test_only_the_owner_and_manager_change_the_shed_layout(): void
    {
        $caretaker = $this->user('caretaker');

        $this->actingAs($caretaker)->get('/sheds')->assertOk();
        $this->actingAs($caretaker)->post('/sheds', ['name' => 'X', 'status' => 'active'])->assertForbidden();
        $this->actingAs($caretaker)->post("/sheds/{$this->pen->shed_id}/pens", ['name' => 'X', 'purpose' => 'Kids'])->assertForbidden();
        $this->actingAs($caretaker)->patch("/pens/{$this->pen->id}", ['name' => 'X', 'purpose' => 'Kids'])->assertForbidden();
    }

    public function test_a_manager_cannot_edit_another_farms_pen(): void
    {
        $manager = $this->user('manager');
        $mine = Tenant::current();
        Tenant::set(Organization::create(['name' => 'Other Farm', 'slug' => 'other-farm'])->id);
        $other = Pen::create(['shed_id' => Shed::create(['code' => 'SHED-T', 'name' => 'Other shed'])->id, 'name' => 'Theirs', 'purpose' => 'Kids']);
        Tenant::set($mine);

        $this->actingAs($manager)->patch("/pens/{$other->id}", ['name' => 'Mine now', 'purpose' => 'Kids'])->assertNotFound();
        $this->actingAs($manager)->post("/sheds/{$other->shed_id}/pens", ['name' => 'Sneaky', 'purpose' => 'Kids'])->assertNotFound();
        $this->assertSame('Theirs', DB::table('pens')->where('id', $other->id)->value('name'));
    }

    public function test_a_task_goes_to_a_department_or_to_one_person(): void
    {
        $manager = $this->user('manager');
        $feeding = Department::where('name', 'Feeding')->firstOrFail();
        $worker = $this->user('feeding');
        $worker->forceFill(['department_id' => $feeding->id])->save();

        $this->actingAs($manager)->post('/tasks', ['title' => 'Fill troughs', 'type' => 'feeding', 'department_id' => $feeding->id, 'priority' => 'normal'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post('/tasks', ['title' => 'Check MC-0001', 'type' => 'observation', 'user_id' => $worker->id, 'priority' => 'high'])->assertSessionHasNoErrors();
        $this->assertSame($feeding->id, Task::where('title', 'Check MC-0001')->value('department_id'));
        $this->actingAs($manager)->post('/tasks', ['title' => 'Nobody', 'type' => 'other', 'priority' => 'normal'])->assertSessionHasErrors(['department_id', 'user_id']);

        $this->actingAs($worker)->get('/tasks')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tasks.0.title', 'Check MC-0001')->where('tasks.0.assignee', $worker->name)->where('tasks.0.mine', true)
            ->where('tasks.1.department', 'Feeding')->where('tasks.1.assignee', null)->where('tasks.1.mine', true));
        $this->actingAs($manager)->get('/tasks')->assertInertia(fn (AssertableInertia $page) => $page->where('tasks.0.mine', false)->where('tasks.1.mine', false));
    }

    public function test_a_task_cannot_be_given_to_a_user_of_another_farm(): void
    {
        $manager = $this->user('manager');
        $outsider = User::create(['name' => 'Outsider', 'email' => 'out@test.local', 'password' => 'x', 'role' => 'caretaker', 'org_id' => Organization::create(['name' => 'Other', 'slug' => 'other'])->id]);

        $this->actingAs($manager)->post('/tasks', ['title' => 'X', 'type' => 'other', 'user_id' => $outsider->id, 'priority' => 'normal'])->assertSessionHasErrors('user_id');
    }

    public function test_the_owner_adds_departments_and_new_users_get_one_from_their_role(): void
    {
        $owner = $this->user('ceo');

        $this->actingAs($owner)->post('/settings/departments', ['name' => 'Dairy'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post('/settings/departments', ['name' => 'Dairy'])->assertSessionHasErrors('name');
        $this->actingAs($owner)->post('/settings/users', ['name' => 'Vet One', 'login' => 'vet1', 'role' => 'vet', 'password' => 'temporary1'])->assertSessionHasNoErrors();
        $this->assertSame('Veterinary', User::where('email', 'vet1')->first()->department->name);

        $dairy = Department::where('name', 'Dairy')->value('id');
        $this->actingAs($owner)->post('/settings/users', ['name' => 'Milker', 'login' => 'milker', 'role' => 'caretaker', 'department_id' => $dairy, 'password' => 'temporary1'])->assertSessionHasNoErrors();
        $this->assertSame($dairy, User::where('email', 'milker')->value('department_id'));
    }
}
