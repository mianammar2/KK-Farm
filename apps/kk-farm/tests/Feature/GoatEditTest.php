<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\AnimalMovement;
use App\Models\Breed;
use App\Models\HealthRecord;
use App\Models\Mating;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RfidTag;
use App\Models\Shed;
use App\Models\User;
use App\Support\ActionCenter;
use App\Support\Farm;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-091: teeth, arrival check, pregnant when bought, editing a goat, removing a wrong entry in two steps. */
class GoatEditTest extends TestCase
{
    use RefreshDatabase;

    private Breed $breed;

    private Pen $pen;

    private Pen $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Edit Farm', 'slug' => 'edit-farm'])->id);
        $this->breed = Breed::create(['name' => 'Teddy', 'default_gestation_days' => 150]);
        $shed = Shed::create(['code' => 'S1', 'name' => 'KKF-BDU-01']);
        $this->pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->other = Pen::create(['shed_id' => $shed->id, 'name' => 'P02', 'purpose' => 'Bucks']);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@edit.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function registerDoe(array $extra = []): Animal
    {
        $this->actingAs($this->user('manager'))->post('/goats', $extra + [
            'origin' => 'purchased', 'breed_id' => $this->breed->id, 'acquired_on' => now()->toDateString(), 'sex' => 'female', 'pen_id' => $this->pen->id,
            'teeth' => '4', 'birth_date' => now()->subMonths(21)->toDateString(), 'temperature_f' => 104.4, 'body_score' => '3', 'latest_weight_kg' => 38,
            'pregnant_at_arrival' => 'yes', 'pregnancy_days' => 90,
        ])->assertSessionHasNoErrors();

        return Animal::latest('created_at')->first();
    }

    public function test_a_bought_pregnant_doe_gets_teeth_an_arrival_check_and_a_breeding_record(): void
    {
        $doe = $this->registerDoe();
        $this->assertSame(['4', 'pregnant'], [$doe->teeth, $doe->repro_status]);

        $check = HealthRecord::where('animal_id', $doe->id)->sole();
        $this->assertSame(['Arrival check: fever', 'open'], [$check->title, $check->status]);
        $this->assertStringContainsString('Temperature 104.4 °F (fever: above 103.5 °F)', $check->notes);
        $task = \App\Models\Task::where('animal_id', $doe->id)->where('type', 'health')->sole();
        $this->assertSame(1, \App\Models\Task::where('animal_id', $doe->id)->where('type', 'kidding')->count(), 'pregnant on arrival: the kidding preparation task is made (D-103)');
        $this->assertSame(['critical', 'health'], [$task->priority, $task->type], 'the vets are alerted');
        $this->assertSame(3, $doe->weights()->first()->body_score);

        $mating = Mating::where('dam_id', $doe->id)->sole();
        $this->assertSame(['pregnant', null], [$mating->status, $mating->sire_id]);
        $this->assertSame(now()->subDays(90)->addDays(150)->toDateString(), $mating->expected_kidding_on->toDateString());

        $this->actingAs($this->user('manager'))->post('/goats', ['origin' => 'purchased', 'breed_id' => $this->breed->id, 'sex' => 'female', 'pen_id' => $this->pen->id, 'temperature_f' => 120])
            ->assertSessionHasErrors('temperature_f');
        // D-093: a pregnant goat needs her temperature (and the months pregnant); otherwise it is optional.
        $this->actingAs($this->user('manager'))->post('/goats', ['origin' => 'purchased', 'breed_id' => $this->breed->id, 'sex' => 'female', 'pen_id' => $this->pen->id, 'pregnant_at_arrival' => 'yes'])
            ->assertSessionHasErrors(['temperature_f', 'pregnancy_days']);
        $this->actingAs($this->user('manager'))->post('/goats', ['origin' => 'purchased', 'breed_id' => $this->breed->id, 'sex' => 'female', 'pen_id' => $this->pen->id, 'repro_status' => 'pregnant'])
            ->assertSessionHasErrors('temperature_f');
        $this->actingAs($this->user('manager'))->post('/goats', ['origin' => 'purchased', 'breed_id' => $this->breed->id, 'sex' => 'female', 'pen_id' => $this->pen->id, 'pregnant_at_arrival' => 'no'])
            ->assertSessionDoesntHaveErrors('temperature_f');
    }

    public function test_every_detail_can_be_edited_and_each_change_stays_in_the_history(): void
    {
        $doe = $this->registerDoe(['pregnant_at_arrival' => 'no', 'temperature_f' => '', 'body_score' => '']);
        $taken = Animal::create(['farm_number' => 'TD-TAKEN', 'sex' => 'female', 'breed_id' => $this->breed->id, 'origin' => 'purchased', 'pen_id' => $this->pen->id]);
        $vet = $this->user('vet');

        $this->actingAs($vet)->get("/goats/{$doe->id}/edit")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Goats/Register')->where('goat.values.teeth', '4'));
        $this->actingAs($vet)->post("/goats/{$doe->id}/edit", ['origin' => 'purchased', 'breed_id' => $this->breed->id, 'sex' => 'female', 'tag_id' => 'TD-TAKEN', 'pen_id' => $this->pen->id])
            ->assertSessionHasErrors('tag_id');
        $this->actingAs($vet)->post("/goats/{$doe->id}/edit", [
            'origin' => 'purchased', 'breed_id' => $this->breed->id, 'sex' => 'female', 'tag_id' => $doe->farm_number, 'pen_id' => $this->other->id,
            'name' => 'Rani', 'teeth' => '6', 'purchase_cost_pkr' => 65000, 'birth_date' => $doe->birth_date->toDateString(),
        ])->assertSessionHasNoErrors();

        $doe->refresh();
        $this->assertSame(['Rani', '6', 6500000, $this->other->id], [$doe->name, $doe->teeth, $doe->purchase_cost_minor, $doe->pen_id]);
        $edit = $doe->events()->where('type', 'edit')->sole()->summary;
        $this->assertStringContainsString('Name (optional): empty → Rani', $edit);
        $this->assertStringContainsString('Teeth (permanent incisors): 4 → 6', $edit);
        $this->assertStringContainsString('Purchase Cost PKR: empty → PKR 65,000', $edit);
        $this->assertSame(1, AnimalMovement::where('animal_id', $doe->id)->where('type', 'transfer')->count(), 'a pen change is a recorded move');

        $this->actingAs($this->user('caretaker'))->get("/goats/{$doe->id}/edit")->assertForbidden();
    }

    public function test_a_wrong_goat_is_removed_only_after_confirmation_and_can_be_restored(): void
    {
        $goat = Animal::create(['farm_number' => 'TD-DUP', 'sex' => 'female', 'breed_id' => $this->breed->id, 'origin' => 'purchased', 'pen_id' => $this->pen->id])->refresh();
        Farm::assignTag($goat, RfidTag::create(['tag_code' => 'E200DUP', 'status' => 'in_stock']), 'initial', $this->user('manager'));

        $this->actingAs($this->user('caretaker'))->post("/goats/{$goat->id}/removal", ['reason' => 'Registered twice'])->assertForbidden();
        $this->actingAs($this->user('vet'))->post("/goats/{$goat->id}/removal", ['reason' => 'Registered twice by mistake'])->assertSessionHasNoErrors();
        $this->assertSame('active', $goat->fresh()->status, 'asking does not remove');
        $this->assertSame(['TD-DUP'], array_column(collect(ActionCenter::items($this->user('ceo')))->firstWhere('key', 'removal_requests')['items'], 'text'));
        $this->actingAs($this->user('vet'))->post("/goats/{$goat->id}/removal/decide", ['decision' => 'confirm'])->assertForbidden();

        $this->actingAs($this->user('ceo'))->post("/goats/{$goat->id}/removal/decide", ['decision' => 'confirm'])->assertRedirect('/goats');
        $goat->refresh();
        $this->assertSame(['voided', 'Test ceo'], [$goat->status, $goat->voided_by]);
        $this->assertFalse(Animal::active()->whereKey($goat->id)->exists(), 'out of every count and list');
        $this->assertNull($goat->openAssignment()->first(), 'its tag is free again');
        $this->assertStringContainsString('Asked by Test vet, confirmed by Test ceo: Registered twice by mistake', $goat->events()->where('summary', 'like', 'Removed%')->sole()->summary);
        $this->assertDatabaseHas('animals', ['id' => $goat->id]); // never deleted

        $this->actingAs($this->user('ceo'))->post("/goats/{$goat->id}/restore")->assertRedirect();
        $this->assertSame('active', $goat->fresh()->status);
    }

    /** D-106: teeth set in the Goat Registry give the estimated age; a farm-born goat keeps its real birth date. */
    public function test_teeth_from_the_registry_estimate_the_age(): void
    {
        $bought = Animal::create(['farm_number' => 'TD141026-04', 'sex' => 'female', 'breed_id' => $this->breed->id, 'origin' => 'purchased', 'pen_id' => $this->pen->id])->refresh();
        $born = Animal::create(['farm_number' => 'TD-B', 'sex' => 'female', 'breed_id' => $this->breed->id, 'origin' => 'born_on_farm', 'pen_id' => $this->pen->id, 'birth_date' => now()->subMonths(10)])->refresh();
        $vet = $this->user('vet');

        $this->actingAs($vet)->post("/goats/{$bought->id}/teeth", ['teeth' => '4'])->assertSessionHasNoErrors();
        $bought->refresh();
        $this->assertSame(['4', now()->subMonths(21)->toDateString(), true], [$bought->teeth, $bought->birth_date->toDateString(), $bought->birth_date_is_estimate]);
        $this->assertSame('21 mo (est.)', $bought->ageLabel());
        $this->assertStringContainsString('Teeth (permanent incisors): empty → 4', $bought->events()->where('type', 'edit')->sole()->summary);

        $this->actingAs($vet)->post("/goats/{$born->id}/teeth", ['teeth' => '2'])->assertSessionHasNoErrors();
        $this->assertSame(['2', now()->subMonths(10)->toDateString()], [$born->fresh()->teeth, $born->fresh()->birth_date->toDateString()], 'a real birth date is kept');

        $this->actingAs($this->user('ceo'))->get('/goats')->assertInertia(fn (AssertableInertia $p) => $p->where('canEdit', true)->where('teethOptions.4', '4 teeth (18–24 months)')
            ->where('goats', fn ($g) => collect($g)->firstWhere('number', 'TD141026-04')['teeth'] === '4' && collect($g)->firstWhere('number', 'TD141026-04')['ageMonths'] === 21));
        $this->actingAs($this->user('caretaker'))->post("/goats/{$bought->id}/teeth", ['teeth' => '6'])->assertForbidden();
    }
}
