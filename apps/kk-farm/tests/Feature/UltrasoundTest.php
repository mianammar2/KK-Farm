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
use App\Models\Task;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-166: KK Farms ultrasound records and their automations; the pregnant count comes from the pregnancy records. */
class UltrasoundTest extends TestCase
{
    use RefreshDatabase;

    private Animal $doe;

    private Mating $mating;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Scan Farm', 'slug' => 'scan-farm', 'gestation_days' => 150])->id);
        $breed = Breed::create(['name' => 'Beetal', 'default_gestation_days' => 150]);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->doe = Animal::create(['farm_number' => 'BT-1', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $pen->id]);
        $this->mating = Mating::create(['dam_id' => $this->doe->id, 'mated_on' => now()->subDays(50), 'expected_kidding_on' => now()->addDays(100), 'status' => 'open', 'recorded_by' => 'Test']);
    }

    private function user(string $role = 'vet'): User
    {
        return User::firstOrCreate(['email' => "$role@scan.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function scan(array $data, string $role = 'vet')
    {
        return $this->actingAs($this->user($role))->post("/breeding/matings/{$this->mating->id}/check", $data + ['checked_on' => now()->toDateString()]);
    }

    public function test_twins_confirmed_with_heartbeats_mark_her_pregnant_move_the_kidding_date_and_flag_nutrition(): void
    {
        $this->scan(['ultrasound_status' => 'PCF', 'fetuses' => 2, 'viability' => 'HBD', 'estimated_day' => 60])->assertSessionHasNoErrors();
        $c = PregnancyCheck::sole();
        $this->assertSame(['PCF', 'pregnant', 2, 'HBD', 50, 'Multiple pregnancy: extra nutrition and closer monitoring'], [$c->ultrasound_status, $c->result, $c->fetuses, $c->viability, $c->pregnancy_day, $c->action]);
        $this->assertSame(['pregnant', now()->subDays(60)->addDays(150)->toDateString()], [$this->mating->fresh()->status, $this->mating->fresh()->expected_kidding_on->toDateString()]);
        $this->assertTrue(Task::where('type', 'feeding')->where('title', 'like', 'BT-1: Twin Pregnancy%')->exists());
    }

    public function test_inconclusive_not_pregnant_and_hydrometra_follow_ups(): void
    {
        $this->scan(['ultrasound_status' => 'INC'])->assertSessionHasNoErrors();
        $this->assertSame('inconclusive', $this->mating->fresh()->status);
        $this->assertTrue(Task::where('type', 'pregnancy_check')->whereDate('due_on', now()->addDays(14))->exists());

        $this->scan(['ultrasound_status' => 'HYD'])->assertSessionHasNoErrors();
        $this->assertSame(['not_pregnant', 'open'], [$this->mating->fresh()->status, $this->doe->fresh()->repro_status]);
        $this->assertTrue(Task::where('title', 'like', 'Vet investigation: hydrometra%')->exists());
    }

    public function test_not_pregnant_adds_a_breeding_reassessment(): void
    {
        $this->scan(['ultrasound_status' => 'NPG', 'fetuses' => 0])->assertSessionHasNoErrors();
        $this->assertSame('not_pregnant', $this->mating->fresh()->status);
        $this->assertTrue(Task::where('type', 'breeding')->where('title', 'Breeding reassessment: BT-1')->exists());
    }

    public function test_suspected_fetal_loss_alerts_the_vet_but_keeps_the_pregnancy(): void
    {
        $this->mating->update(['status' => 'pregnant']);
        $this->scan(['ultrasound_status' => 'SFL', 'viability' => 'HBN'])->assertSessionHasNoErrors();
        $this->assertSame('pregnant', $this->mating->fresh()->status);
        $this->assertSame(0, Kidding::count());
        $this->assertTrue(Task::where('priority', 'high')->where('title', 'like', 'Vet alert: suspected fetal loss%')->exists());
    }

    public function test_confirmed_loss_is_recorded_as_a_pregnancy_loss_with_isolation(): void
    {
        $this->mating->update(['status' => 'pregnant']);
        $this->scan(['ultrasound_status' => 'CPL'])->assertSessionHasNoErrors();
        $this->assertSame('aborted', $this->mating->fresh()->status);
        $this->assertSame(['ABT', 'PAR'], [Kidding::sole()->outcome, Kidding::sole()->mother_status]); // day 50: abortion, not early embryonic loss
        $this->assertSame(['ISO-01', 'monitoring'], [$this->doe->fresh()->pen->name, $this->doe->fresh()->reproNow()]);
        $this->assertTrue(Task::where('type', 'biosecurity')->exists());
    }

    public function test_only_a_vet_records_an_ultrasound_and_the_old_form_still_works(): void
    {
        $this->scan(['ultrasound_status' => 'PCF'], 'caretaker')->assertForbidden();
        $this->scan(['result' => 'pregnant', 'method' => 'ultrasound', 'fetuses' => 1])->assertSessionHasNoErrors();
        $this->assertSame('PCF', PregnancyCheck::sole()->ultrasound_status);
    }

    public function test_the_dashboard_counts_pregnant_does_from_the_pregnancy_records(): void
    {
        $this->doe->update(['repro_status' => 'pregnant', 'repro_state' => 'pregnant']); // typed as pregnant, but no confirmed pregnancy yet
        $this->actingAs($this->user('ceo'))->get('/')->assertInertia(fn (AssertableInertia $p) => $p->where('stats.pregnant', 0)->where('stats.awaitingScan', 1));
        $this->mating->update(['status' => 'pregnant', 'expected_kidding_on' => now()->addDays(5)]);
        $this->actingAs($this->user('ceo'))->get('/')->assertInertia(fn (AssertableInertia $p) => $p->where('stats.pregnant', 1)->where('stats.pregnantNear', 1));
        $this->actingAs($this->user('vet'))->get("/goats/{$this->doe->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('scanMating.id', $this->mating->id)->has('ultrasoundOptions.ultrasound', 11));
    }
}
