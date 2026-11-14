<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Kidding;
use App\Models\Lactation;
use App\Models\Mating;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-161: pregnancy outcome classification, automatic kid records, vet and biosecurity alerts, reproduction KPIs. */
class PregnancyOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private Animal $doe;

    private Mating $mating;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Outcome Farm', 'slug' => 'outcome-farm'])->id);
        $breed = Breed::create(['name' => 'Teddy', 'default_gestation_days' => 150]);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->doe = Animal::create(['farm_number' => 'TD-1', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $pen->id]);
        $buck = Animal::create(['farm_number' => 'TD-B', 'sex' => 'male', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $pen->id]);
        $this->mating = Mating::create(['dam_id' => $this->doe->id, 'sire_id' => $buck->id, 'mated_on' => now()->subDays(148), 'expected_kidding_on' => now()->addDays(2), 'status' => 'pregnant', 'recorded_by' => 'Test']);
    }

    private function user(string $role = 'ceo'): User
    {
        return User::firstOrCreate(['email' => "$role@outcome.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_a_normal_kidding_registers_each_kid_with_its_sex_birth_outcome_and_birth_weight(): void
    {
        $this->actingAs($this->user('caretaker'))->post("/breeding/matings/{$this->mating->id}/kidding", [
            'outcome' => 'NKD', 'kidded_on' => now()->toDateString(), 'delivery_method' => 'NRM', 'mother_status' => 'LCT',
            'kids' => [['sex' => 'female', 'weak' => false, 'weight_kg' => '3.2'], ['sex' => 'male', 'weak' => true, 'weight_kg' => '']],
            'still_male' => 0, 'still_female' => 0,
        ])->assertSessionHasNoErrors();

        $k = Kidding::sole();
        $this->assertSame(['NKD', 'NRM', 'LCT', 2, 1, 1, 1, 148], [$k->outcome, $k->delivery_method, $k->mother_status, $k->born_alive, $k->live_female, $k->live_male, $k->weak, $k->gestation_days]);
        $kids = Animal::where('birth_kidding_id', $k->id)->orderBy('sex')->get();
        $this->assertSame([['female', 'LVB'], ['male', 'BAW']], $kids->map(fn ($a) => [$a->sex, $a->birth_outcome])->all());
        $this->assertTrue($kids->every(fn ($a) => $a->dam_id === $this->doe->id && $a->birth_date->isToday()));
        $this->assertEquals(3.2, (float) WeightRecord::where('animal_id', $kids[0]->id)->value('weight_kg'));
        $this->assertSame('kidded', $this->mating->fresh()->status);
        $this->assertTrue(Lactation::where('animal_id', $this->doe->id)->exists());
        $this->assertTrue(Task::where('type', 'observation')->where('title', 'like', 'Watch weak kid%')->exists());
    }

    public function test_an_abortion_closes_the_pregnancy_as_lost_and_raises_a_vet_review_and_a_biosecurity_task(): void
    {
        $this->actingAs($this->user('vet'))->post("/breeding/matings/{$this->mating->id}/kidding", [
            'outcome' => 'ABT', 'kidded_on' => now()->toDateString(), 'abortion_cause' => 'Fever before', 'kids' => [], 'still_male' => 0, 'still_female' => 0,
        ])->assertSessionHasNoErrors();

        $k = Kidding::sole();
        $this->assertSame(['ABT', 'NAP', 'PAR', 0, 0], [$k->outcome, $k->delivery_method, $k->mother_status, $k->born_alive, $k->stillborn]);
        $this->assertSame('aborted', $this->mating->fresh()->status);
        $this->assertSame('PLS', $this->mating->fresh()->pregnancyStatus(10));
        $this->assertFalse(Lactation::where('animal_id', $this->doe->id)->exists());
        $this->assertSame(['biosecurity' => 'critical', 'health' => 'high'], Task::where('animal_id', $this->doe->id)->whereIn('type', ['health', 'biosecurity'])->orderBy('type')->pluck('priority', 'type')->all());
        $this->assertSame(0, Animal::whereNotNull('birth_kidding_id')->count());
    }

    public function test_the_outcome_must_agree_with_the_kids(): void
    {
        $post = fn (array $d) => $this->actingAs($this->user())->post("/breeding/matings/{$this->mating->id}/kidding", $d + ['kidded_on' => now()->toDateString()]);
        $post(['outcome' => 'MBO', 'kids' => [['sex' => 'female']], 'still_male' => 0, 'still_female' => 0])->assertSessionHasErrors('outcome');
        $post(['outcome' => 'STB', 'kids' => [['sex' => 'female']], 'still_male' => 1])->assertSessionHasErrors('outcome');
        $post(['outcome' => 'ABT', 'kids' => [['sex' => 'female']]])->assertSessionHasErrors('outcome');
        $this->assertSame(0, Kidding::count());
        $post(['outcome' => 'MBO', 'kids' => [['sex' => 'male']], 'still_female' => 1, 'mother_status' => 'RTP'])->assertSessionHasNoErrors();
        $this->assertSame([1, 1, 1], [Kidding::sole()->born_alive, Kidding::sole()->stillborn, Kidding::sole()->still_female]);
        $this->assertTrue(Task::where('title', 'like', 'Vet review: TD-1 – Retained Placenta')->exists());
    }

    public function test_breeding_page_and_dashboard_show_the_reproduction_kpis(): void
    {
        $this->actingAs($this->user())->post("/breeding/matings/{$this->mating->id}/kidding", ['outcome' => 'NKD', 'kids' => [['sex' => 'female'], ['sex' => 'male']], 'mother_status' => 'HNR'])->assertSessionHasNoErrors();
        $this->actingAs($this->user())->get('/breeding')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('kpis.events', 1)->where('kpis.deliveries', 1)->where('kpis.live', 2)->where('kpis.recovery', 100)->where('kiddings.0.outcome', 'NKD')->has('options.outcomes', 9));
        $this->actingAs($this->user())->get('/')->assertInertia(fn (AssertableInertia $p) => $p->where('repro.live', 2));
    }

    public function test_the_goat_page_offers_the_outcome_for_a_pregnant_doe(): void
    {
        $this->actingAs($this->user())->get("/goats/{$this->doe->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('pregnancy.matingId', $this->mating->id)->where('can.breeding', true)->has('outcomeOptions.mother', 8));
    }

    public function test_after_a_delivery_or_loss_the_doe_is_monitored_for_3_days_then_lactating_or_open(): void
    {
        $this->doe->update(['repro_status' => 'pregnant', 'repro_state' => 'pregnant']);
        $this->actingAs($this->user())->post("/breeding/matings/{$this->mating->id}/kidding", ['outcome' => 'NKD', 'kids' => [['sex' => 'female']], 'kidded_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $doe = $this->doe->fresh();
        $this->assertSame(['monitoring', 'lactating', 'NKD'], [$doe->reproNow(), $doe->repro_status, $doe->monitor_reason]);
        $this->assertSame(now()->addDays(3)->toDateString(), $doe->monitor_until->toDateString());
        $this->assertTrue(Task::where('title', 'like', 'Monitor TD-1 for 3 days%')->whereDate('due_on', now()->addDays(3))->exists());
        $this->actingAs($this->user())->get("/goats/{$doe->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('goat.repro', 'monitoring'));

        $this->travel(3)->days();
        $this->assertSame('monitoring', $doe->fresh()->reproNow()); // the 3rd day is still monitored
        $this->travel(1)->days();
        $this->assertSame('lactating', $doe->fresh()->reproNow());
    }

    public function test_after_an_abortion_the_doe_is_open_once_monitoring_ends(): void
    {
        $this->doe->update(['repro_status' => 'pregnant', 'repro_state' => 'pregnant']);
        $this->actingAs($this->user())->post("/breeding/matings/{$this->mating->id}/kidding", ['outcome' => 'EEL', 'kids' => []])->assertSessionHasNoErrors();
        $doe = $this->doe->fresh();
        $this->assertSame(['monitoring', 'open'], [$doe->reproNow(), $doe->repro_status]);
        $this->travel(4)->days();
        $this->assertNull($doe->fresh()->reproNow());
    }

    public function test_the_maternity_unit_takes_the_mother_and_kids_and_isolates_after_a_loss(): void
    {
        $oldPen = $this->doe->pen_id;
        $this->actingAs($this->user())->post("/breeding/matings/{$this->mating->id}/kidding", ['outcome' => 'NKD', 'kids' => [['sex' => 'female'], ['sex' => 'male', 'weak' => true]]])->assertSessionHasNoErrors();
        $shed = \App\Models\Shed::where('type', 'MTU')->sole();
        $this->assertSame(['PCU-01', 'ISO-01', 'KDP-01', 'KDP-02', 'NCU-01', 'PKU-01'], \App\Models\Pen::where('shed_id', $shed->id)->orderByRaw("name <> 'PCU-01', name <> 'ISO-01', name")->pluck('name')->all());
        $doe = $this->doe->fresh();
        $this->assertSame('PCU-01', $doe->pen->name);
        $this->assertTrue(\App\Models\AnimalMovement::where('animal_id', $doe->id)->where('from_pen_id', $oldPen)->where('to_pen_id', $doe->pen_id)->exists());
        $this->assertEqualsCanonicalizing(['female' => 'PCU-01', 'male' => 'NCU-01'], Animal::where('dam_id', $doe->id)->with('pen')->get()->mapWithKeys(fn ($k) => [$k->sex => $k->pen->name])->all());

        // a second doe that aborts goes to isolation, not with the mothers and kids
        $doe2 = Animal::create(['farm_number' => 'TD-2', 'sex' => 'female', 'breed_id' => $this->doe->breed_id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $oldPen]);
        $m2 = Mating::create(['dam_id' => $doe2->id, 'mated_on' => now()->subDays(60), 'status' => 'pregnant', 'recorded_by' => 'Test']);
        $this->actingAs($this->user())->post("/breeding/matings/{$m2->id}/kidding", ['outcome' => 'ABT', 'kids' => []])->assertSessionHasNoErrors();
        $this->assertSame('ISO-01', $doe2->fresh()->pen->name);
        $this->assertSame(1, \App\Models\Shed::where('type', 'MTU')->count());
    }

    public function test_the_maternity_round_moves_does_near_kidding_to_pre_kidding_and_catches_up_after_outcomes(): void
    {
        $far = Animal::create(['farm_number' => 'TD-FAR', 'sex' => 'female', 'breed_id' => $this->doe->breed_id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $this->doe->pen_id]);
        Mating::create(['dam_id' => $far->id, 'mated_on' => now()->subDays(60), 'expected_kidding_on' => now()->addDays(90), 'status' => 'pregnant', 'recorded_by' => 'Test']);

        // An abortion recorded before the Maternity Unit existed: she is still in her old pen, under monitoring.
        $aborted = Animal::create(['farm_number' => 'TD-ABT', 'sex' => 'female', 'breed_id' => $this->doe->breed_id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $this->doe->pen_id,
            'monitor_until' => now()->addDays(2), 'monitor_reason' => 'ABT', 'repro_status' => 'open']);
        Kidding::create(['dam_id' => $aborted->id, 'kidded_on' => now()->subDay(), 'born_alive' => 0, 'stillborn' => 0, 'outcome' => 'ABT', 'recorded_by' => 'Test']);

        $this->artisan('kkfarm:maternity-round')->assertSuccessful();
        $this->assertSame('PKU-01', $this->doe->fresh()->pen->name);      // expected in 2 days, inside the 10-day window
        $this->assertNotSame('PKU-01', $far->fresh()->pen?->name);         // 90 days away: stays
        $this->assertSame('ISO-01', $aborted->fresh()->pen->name);
        $this->assertTrue(\App\Models\AnimalMovement::where('animal_id', $this->doe->id)->where('recorded_by', 'System (maternity round)')->exists());

        // staff move her on to a Kidding Pen: the next round leaves her there
        \App\Support\Movements::transfer($this->doe->fresh(), \App\Support\Maternity::pen('KDP-01'), $this->user());
        $this->artisan('kkfarm:maternity-round')->assertSuccessful();
        $this->assertSame('KDP-01', $this->doe->fresh()->pen->name);
    }

    /** D-171: a kid from a kidding record is listed under Newborns to Complete until its birth weight is entered. */
    public function test_newborns_without_a_birth_weight_are_listed_to_complete(): void
    {
        $this->actingAs($this->user())->post("/breeding/matings/{$this->mating->id}/kidding", ['outcome' => 'NKD', 'kids' => [['sex' => 'female'], ['sex' => 'male', 'weight_kg' => '3.1']]])->assertSessionHasNoErrors();
        $this->actingAs($this->user())->get('/goats')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('goats', fn ($goats) => collect($goats)->filter(fn ($g) => in_array('newborn', $g['tags'], true))->pluck('sex')->values()->all() === ['female'])
            ->where('showLabels.newborn', 'Newborns to complete'));
    }
}
