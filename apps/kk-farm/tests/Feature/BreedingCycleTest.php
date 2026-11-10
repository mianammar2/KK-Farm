<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Kidding;
use App\Models\Mating;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\PregnancyCheck;
use App\Models\Shed;
use App\Models\User;
use App\Models\Weaning;
use App\Models\WeightRecord;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-075: breeding cycle — matings, pregnancy checks, kidding and weaning (workbook sheets 4–7). */
class BreedingCycleTest extends TestCase
{
    use RefreshDatabase;

    private Animal $doe;

    private Animal $buck;

    private Pen $pen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Breed Farm', 'slug' => 'breed-farm'])->id);
        $breed = Breed::create(['name' => 'Beetal', 'default_gestation_days' => 150]);
        $this->pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->doe = Animal::create(['farm_number' => 'DOE-1', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $this->pen->id]);
        $this->buck = Animal::create(['farm_number' => 'BUCK-1', 'sex' => 'male', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $this->pen->id]);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@breed.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function mate(string $on, array $extra = []): Mating
    {
        $this->actingAs($this->user('caretaker'))->post('/breeding/matings', $extra + ['dam_id' => $this->doe->id, 'sire_id' => $this->buck->id, 'mated_on' => $on, 'method' => 'natural', 'heat_observed' => 'yes'])->assertSessionHasNoErrors();

        return Mating::latest('id')->firstOrFail();
    }

    public function test_a_mating_takes_its_dates_from_the_farm_settings_and_counts_the_service_cycle(): void
    {
        Organization::whereKey(Tenant::current())->update(['gestation_days' => 148, 'pregnancy_check_days' => 35]);
        $first = $this->mate(now()->subDays(40)->toDateString());
        $this->assertSame([now()->subDays(40)->addDays(148)->toDateString(), now()->subDays(5)->toDateString(), 1], [$first->expected_kidding_on->toDateString(), $first->check_due_on->toDateString(), $first->service_cycle]);
        $this->assertSame('mated', $this->doe->fresh()->repro_status);
        $this->assertSame('check_overdue', $first->alert(14), 'pending and past its check date');

        $second = $this->mate(now()->subDays(10)->toDateString(), ['method' => 'pasture']);
        $this->assertSame(2, $second->service_cycle);
        $this->actingAs($this->user('caretaker'))->post('/breeding/matings', ['dam_id' => $this->buck->id, 'mated_on' => now()->toDateString(), 'method' => 'natural'])->assertStatus(422);
        $this->actingAs($this->user('caretaker'))->post('/breeding/matings', ['dam_id' => $this->doe->id, 'sire_id' => $this->doe->id, 'mated_on' => now()->toDateString(), 'method' => 'natural'])->assertSessionHasErrors('sire_id');
    }

    public function test_pregnancy_checks_are_kept_and_the_outcome_follows_the_latest(): void
    {
        $m = $this->mate(now()->subDays(35)->toDateString());
        $vet = $this->user('vet');

        $this->actingAs($this->user('caretaker'))->post("/breeding/matings/{$m->id}/check", ['result' => 'pregnant'])->assertForbidden();
        $this->actingAs($vet)->post("/breeding/matings/{$m->id}/check", ['result' => 'inconclusive', 'method' => 'ultrasound'])->assertSessionHasErrors('next_check_on');
        $this->actingAs($vet)->post("/breeding/matings/{$m->id}/check", ['result' => 'inconclusive', 'method' => 'ultrasound', 'next_check_on' => now()->addDays(5)->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(['inconclusive', 'check_due'], [$m->fresh()->status, $this->doe->fresh()->repro_status]);
        $this->assertSame('recheck_due', $m->fresh()->load('checks')->alert(14));

        $this->actingAs($vet)->post("/breeding/matings/{$m->id}/check", ['result' => 'pregnant', 'method' => 'ultrasound', 'fetuses' => 2])->assertSessionHasNoErrors();
        $this->assertSame(2, PregnancyCheck::where('mating_id', $m->id)->count());
        $this->assertSame(['pregnant', 'pregnant'], [$m->fresh()->status, $this->doe->fresh()->repro_status]);
    }

    public function test_a_kidding_records_the_litter_and_registers_linked_kids_on_the_kidding_date(): void
    {
        $m = $this->mate(now()->subDays(152)->toDateString());
        $on = now()->subDays(2)->toDateString();

        $this->actingAs($this->user('caretaker'))->post("/breeding/matings/{$m->id}/kidding", ['alive' => 0, 'stillborn' => 0, 'females' => 0])->assertSessionHasErrors('alive');
        $this->actingAs($this->user('caretaker'))->post("/breeding/matings/{$m->id}/kidding", ['alive' => 2, 'stillborn' => 1, 'females' => 1, 'kidded_on' => $on, 'assisted' => 'yes', 'attendant' => 'Imran'])->assertSessionHasNoErrors();

        $kidding = Kidding::sole();
        $this->assertSame(['yes', 'Imran', 'Triplet'], [$kidding->assisted, $kidding->attendant, Kidding::litterType(3)]);
        $kids = Animal::where('birth_kidding_id', $kidding->id)->get();
        $this->assertCount(2, $kids);
        $this->assertTrue($kids->every(fn ($k) => $k->birth_date->toDateString() === $on && $k->dam_id === $this->doe->id && $k->sire_id === $this->buck->id && $k->pen_id === $this->doe->fresh()->pen_id && $k->kid_stage === 'newborn')); // D-163: together in post-kidding care
        $this->assertSame(['kidded', 'lactating'], [$m->fresh()->status, $this->doe->fresh()->repro_status]);
        $this->actingAs($this->user('ceo'))->get('/breeding')->assertInertia(fn (AssertableInertia $p) => $p->where('kiddings.0.litter', 'Triplet')->has('kiddings.0.kids', 2));
    }

    public function test_kids_appear_for_weaning_and_weaning_saves_weight_stage_and_move(): void
    {
        Organization::whereKey(Tenant::current())->update(['weaning_age_days' => 84]);
        $kid = Animal::create(['farm_number' => 'KID-1', 'sex' => 'female', 'breed_id' => $this->doe->breed_id, 'birth_date' => now()->subDays(80), 'origin' => 'born_on_farm', 'dam_id' => $this->doe->id, 'pen_id' => $this->pen->id]);
        Animal::create(['farm_number' => 'KID-YOUNG', 'sex' => 'male', 'breed_id' => $this->doe->breed_id, 'birth_date' => now()->subDays(30), 'origin' => 'born_on_farm', 'pen_id' => $this->pen->id]);
        Animal::create(['farm_number' => 'BOUGHT-1', 'sex' => 'female', 'breed_id' => $this->doe->breed_id, 'birth_date' => now()->subDays(85), 'origin' => 'purchased', 'pen_id' => $this->pen->id]);
        Animal::create(['farm_number' => 'OLD-1', 'sex' => 'female', 'breed_id' => $this->doe->breed_id, 'birth_date' => now()->subDays(300), 'origin' => 'born_on_farm', 'pen_id' => $this->pen->id]);
        $weaners = Pen::create(['shed_id' => $this->pen->shed_id, 'name' => 'P02', 'purpose' => 'Weaners']);
        $caretaker = $this->user('caretaker');

        $this->actingAs($caretaker)->get('/breeding')->assertInertia(fn (AssertableInertia $p) => $p->has('weaning', 1)->where('weaning.0.number', 'KID-1')->where('weaning.0.state', 'soon'));

        $this->actingAs($caretaker)->post('/breeding/weanings', ['kid_id' => $kid->id, 'status' => 'postponed', 'postponed_to' => now()->addDays(10)->toDateString(), 'notes' => 'underweight'])->assertSessionHasNoErrors();
        $this->actingAs($caretaker)->get('/breeding')->assertInertia(fn (AssertableInertia $p) => $p->where('weaning.0.state', 'postponed'));

        $this->actingAs($caretaker)->post('/breeding/weanings', ['kid_id' => $kid->id, 'status' => 'completed', 'weaned_on' => now()->toDateString(), 'weight_kg' => 14.5, 'method' => 'separate_pen', 'pen_id' => $weaners->id])->assertSessionHasNoErrors();
        $kid->refresh();
        $this->assertSame(['weaned', $weaners->id], [$kid->kid_stage, $kid->pen_id]);
        $this->assertSame(14.5, (float) WeightRecord::where('animal_id', $kid->id)->where('context', 'weaning')->value('weight_kg'));
        $this->actingAs($caretaker)->get('/breeding')->assertInertia(fn (AssertableInertia $p) => $p->has('weaning', 0)->where('weaned.0.kid', 'KID-1')->where('weaned.0.age', 80));
        $this->actingAs($caretaker)->post('/breeding/weanings', ['kid_id' => $kid->id, 'status' => 'completed', 'weaned_on' => now()->toDateString()])->assertSessionHasErrors('kid_id');
        $this->assertSame(2, Weaning::count());
    }

    public function test_only_the_owner_changes_breeding_settings(): void
    {
        $this->actingAs($this->user('manager'))->post('/breeding/settings', ['gestation' => 150, 'check' => 30, 'alert' => 14, 'weaning' => 84])->assertForbidden();
        $this->actingAs($this->user('ceo'))->post('/breeding/settings', ['gestation' => 200, 'check' => 30, 'alert' => 14, 'weaning' => 84])->assertSessionHasErrors('gestation');
        $this->actingAs($this->user('ceo'))->post('/breeding/settings', ['gestation' => 149, 'check' => 28, 'alert' => 10, 'weaning' => 90])->assertSessionHasNoErrors();
        $this->assertSame([149, 28, 10, 90], array_values(Organization::whereKey(Tenant::current())->first(['gestation_days', 'pregnancy_check_days', 'kidding_alert_days', 'weaning_age_days'])->only(['gestation_days', 'pregnancy_check_days', 'kidding_alert_days', 'weaning_age_days'])));
    }
}
