<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthCase;
use App\Models\HealthCheckup;
use App\Models\HealthRecord;
use App\Models\Mating;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\PregnancyCheck;
use App\Models\Shed;
use App\Models\User;
use App\Support\ActionCenter;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-095: routine health checkups. */
class CheckupTest extends TestCase
{
    use RefreshDatabase;

    private Animal $doe;

    private Animal $buck;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Check Farm', 'slug' => 'check-farm'])->id);
        $breed = Breed::create(['name' => 'Teddy', 'default_gestation_days' => 150]);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'KKF-BDU-01'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->doe = Animal::create(['farm_number' => 'TD141026-01', 'sex' => 'female', 'breed_id' => $breed->id, 'origin' => 'purchased', 'pen_id' => $pen->id, 'birth_date' => now()->subYears(2)])->refresh();
        $this->buck = Animal::create(['farm_number' => 'TD-B1', 'sex' => 'male', 'breed_id' => $breed->id, 'origin' => 'purchased', 'pen_id' => $pen->id, 'birth_date' => now()->subYears(2)])->refresh();
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@check.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_a_back_dated_checkup_with_a_pregnant_ultrasound_updates_breeding_and_the_dashboard(): void
    {
        $vet = $this->user('vet');
        $this->assertSame(['TD141026-01', 'TD-B1'], array_column(collect(ActionCenter::items($vet))->firstWhere('key', 'checkups_due')['items'], 'text'));

        $this->actingAs($vet)->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => '2026-10-21', 'temperature_f' => 102.4,
            'body_score' => 3, 'weight_kg' => 41, 'ultrasound' => 'pregnant'])->assertSessionHasErrors('days_pregnant');
        $this->actingAs($vet)->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => '2026-10-21', 'temperature_f' => 102.4,
            'body_score' => 3, 'weight_kg' => 41, 'ultrasound' => 'pregnant', 'days_pregnant' => 60, 'fetuses' => 2, 'sire_id' => $this->buck->id, 'outcome' => 'healthy'])->assertSessionHasNoErrors();

        $doe = $this->doe->fresh();
        $this->assertSame('pregnant', $doe->repro_status);
        $mating = Mating::where('dam_id', $doe->id)->sole();
        $this->assertSame(['pregnant', $this->buck->id, '2026-08-22', '2027-01-19'], [$mating->status, $mating->sire_id, $mating->mated_on->toDateString(), $mating->expected_kidding_on->toDateString()]);
        $this->assertSame(['ultrasound', 'pregnant', 2], [PregnancyCheck::sole()->method, PregnancyCheck::sole()->result, PregnancyCheck::sole()->fetuses]);
        $this->assertSame([41.0, 3], [(float) $doe->weights()->first()->weight_kg, $doe->weights()->first()->body_score]);
        $this->assertSame(0, HealthRecord::count(), 'all normal: nothing left open');
        $this->actingAs($this->user('ceo'))->get('/')->assertInertia(fn (AssertableInertia $p) => $p->where('stats.pregnant', 1));
        $this->assertSame(['TD141026-01', 'TD-B1'], array_column(collect(ActionCenter::items($vet))->firstWhere('key', 'checkups_due')['items'], 'text'), '21 Oct is more than 14 days ago: due again');
        $this->actingAs($vet)->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => now()->subDays(2)->toDateString(), 'outcome' => 'healthy'])->assertSessionHasNoErrors();
        $this->assertSame(['TD-B1'], array_column(collect(ActionCenter::items($vet))->firstWhere('key', 'checkups_due')['items'], 'text'), 'checked within 14 days');
    }

    public function test_readings_outside_normal_are_flagged_and_a_finding_opens_a_case(): void
    {
        $this->actingAs($this->user('ceo'))->post('/health/checkups', ['animal_id' => $this->buck->id, 'checked_on' => now()->toDateString(), 'temperature_f' => 105.1, 'outcome' => 'monitor'])->assertSessionHasNoErrors();
        $this->assertSame(['Temperature 105.1 °F (high)'], HealthCheckup::sole()->flags());
        $this->assertSame(['open', 'monitor'], [HealthRecord::sole()->status, $this->buck->fresh()->health_flag]);

        // The administrator can open a case from a checkup, as the vet can; a caretaker cannot record checkups.
        $this->actingAs($this->user('ceo'))->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => now()->toDateString(), 'temperature_f' => 104.8, 'outcome' => 'sick', 'open_case' => true, 'condition' => 'Pneumonia', 'severity' => 'high'])->assertSessionHasNoErrors();
        $case = HealthCase::sole();
        $this->assertSame(['Pneumonia', 'high', 'sick'], [$case->condition, $case->severity, $this->doe->fresh()->health_flag]);
        $this->assertSame($case->id, HealthCheckup::where('animal_id', $this->doe->id)->sole()->health_case_id);
        $this->actingAs($this->user('caretaker'))->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => now()->toDateString(), 'outcome' => 'healthy'])->assertForbidden();
        $this->actingAs($this->user('manager'))->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => now()->toDateString(), 'outcome' => 'sick', 'open_case' => true, 'condition' => 'X'])->assertForbidden();
    }

    public function test_a_not_pregnant_ultrasound_closes_an_assumed_pregnancy(): void
    {
        $this->doe->update(['repro_status' => 'pregnant']);
        $m = Mating::create(['dam_id' => $this->doe->id, 'mated_on' => now()->subDays(60), 'method' => 'natural', 'status' => 'pregnant', 'expected_kidding_on' => now()->addDays(90), 'recorded_by' => 'x']);
        $this->actingAs($this->user('vet'))->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => now()->toDateString(), 'ultrasound' => 'not_pregnant', 'outcome' => 'healthy'])->assertSessionHasNoErrors();
        $this->assertSame(['not_pregnant', 'open'], [$m->fresh()->status, $this->doe->fresh()->repro_status]);
    }

    /** D-100: 35 days pregnant on 21 Oct is day 42 on 28 Oct; the goat page shows the thread with weights by day. */
    public function test_the_pregnancy_days_count_on_from_the_first_check_and_show_as_a_thread(): void
    {
        $vet = $this->user('vet');
        $this->actingAs($vet)->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => '2026-10-21', 'weight_kg' => 18.6, 'ultrasound' => 'pregnant', 'days_pregnant' => 35, 'outcome' => 'healthy'])->assertSessionHasNoErrors();
        $this->actingAs($vet)->get('/health')->assertInertia(fn (AssertableInertia $p) => $p->where('checkups.goats.0.matedOn', '2026-09-16'));
        $this->actingAs($vet)->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => '2026-10-28', 'weight_kg' => 19.4, 'ultrasound' => 'pregnant', 'days_pregnant' => 42, 'outcome' => 'healthy'])->assertSessionHasNoErrors();

        $this->assertSame(1, Mating::count(), 'the second check confirms the same pregnancy');
        $this->assertSame('2026-09-16', Mating::sole()->mated_on->toDateString());
        $this->actingAs($vet)->get("/goats/{$this->doe->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('pregnancy.checks.0.day', 35)->where('pregnancy.checks.1.day', 42)->where('pregnancy.checks.1.weight', 19.4)
            ->where('pregnancy.weights.1.day', 42)->where('pregnancy.matedOn', '16 Sep 2026')->where('pregnancy.expected', '13 Feb 2027'));
    }

    /** D-101: a weight-only check of a pregnant doe carries her day of pregnancy; older checkups are filled in too. */
    public function test_a_check_without_ultrasound_gets_the_counted_day_of_pregnancy(): void
    {
        $vet = $this->user('vet');
        // Entered before the pregnancy was known (no day), then the ultrasound of 21 Oct: 35 days.
        $this->actingAs($vet)->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => '2026-10-28', 'weight_kg' => 19.5, 'outcome' => 'healthy'])->assertSessionHasNoErrors();
        $this->assertNull(HealthCheckup::sole()->days_pregnant);
        $this->actingAs($vet)->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => '2026-10-21', 'weight_kg' => 18.6, 'ultrasound' => 'pregnant', 'days_pregnant' => 35, 'outcome' => 'healthy'])->assertSessionHasNoErrors();
        $this->assertSame([35, 42], HealthCheckup::orderBy('checked_on')->pluck('days_pregnant')->all(), 'the 28 Oct weight-only check is day 42');

        $this->actingAs($vet)->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => '2026-11-04', 'weight_kg' => 20.1, 'outcome' => 'healthy'])->assertSessionHasNoErrors();
        $this->assertSame(49, HealthCheckup::where('checked_on', '2026-11-04')->sole()->days_pregnant);
        $this->actingAs($vet)->get('/health')->assertInertia(fn (AssertableInertia $p) => $p->where('checkups.recent.0.days', 49));
    }

    /** D-102: scanning a goat offers what to do, with her day of pregnancy; the goat page carries the entry form. */
    public function test_a_scan_offers_what_to_do_and_the_goat_page_has_the_entry(): void
    {
        $vet = $this->user('vet');
        \App\Support\Farm::assignTag($this->doe, \App\Models\RfidTag::create(['tag_code' => 'E200SCAN01', 'status' => 'in_stock']), 'initial', $this->user('manager'));
        $this->actingAs($vet)->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => now()->subDays(7)->toDateString(), 'ultrasound' => 'pregnant', 'days_pregnant' => 35, 'outcome' => 'healthy'])->assertSessionHasNoErrors();

        $this->actingAs($vet)->get('/scan?tag=E200SCAN01')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('result.goat.number', 'TD141026-01')->where('result.goat.pregnancyDay', 42)->where('result.goat.can.check', true)->where('result.goat.can.weigh', true));
        $this->actingAs($vet)->get("/goats/{$this->doe->id}?do=checkup")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('healthEntry.goats.0.id', $this->doe->id)->where('healthEntry.canCheck', true)->where('healthEntry.goats.0.matedOn', now()->subDays(42)->toDateString()));
        $this->actingAs($this->user('finance'))->get("/goats/{$this->doe->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('healthEntry', null));
    }

    /** D-103: the kidding alert starts 10 days before (gestation 150), urgent in the last 3; a preparation task is made by itself. */
    public function test_the_kidding_alert_starts_ten_days_before_with_a_task(): void
    {
        $vet = $this->user('vet');
        // 142 days pregnant today: kidding in 8 days.
        $this->actingAs($vet)->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => now()->toDateString(), 'ultrasound' => 'pregnant', 'days_pregnant' => 142, 'outcome' => 'healthy'])->assertSessionHasNoErrors();
        $task = \App\Models\Task::where('title', 'Prepare for kidding: TD141026-01')->sole();
        $this->assertSame(['kidding', now()->subDays(2)->toDateString()], [$task->type, $task->due_on->toDateString()], 'due 10 days before the expected kidding');
        $items = collect(ActionCenter::items($this->user('ceo')))->keyBy('key');
        $this->assertSame(['TD141026-01', 'warning'], [$items['kidding_soon']['items'][0]['text'], $items['kidding_soon']['severity']]);
        $this->assertStringContainsString('in 8 days', $items['kidding_soon']['items'][0]['sub']);

        // 6 days later: within 3 days, urgent; the goat page warns.
        $this->travel(6)->days();
        $items = collect(ActionCenter::items($this->user('ceo')))->keyBy('key');
        $this->assertSame('critical', $items['kidding_imminent']['severity']);
        $this->actingAs($vet)->get("/goats/{$this->doe->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('pregnancy.daysLeft', 2)->where('pregnancy.alertDays', 10));

        // Kidded: the task is done.
        Mating::sole()->update(['status' => 'kidded']);
        $this->assertSame('completed', $task->fresh()->status);
    }

    /** D-104: a weight drop while pregnant and a fever cannot be saved as Healthy; the vet gets an urgent task. */
    public function test_a_weight_drop_and_a_fever_set_watch_and_alert_the_vet(): void
    {
        $vet = $this->user('vet');
        $this->actingAs($vet)->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => '2026-10-21', 'weight_kg' => 18.6, 'ultrasound' => 'pregnant', 'days_pregnant' => 35, 'outcome' => 'healthy'])->assertSessionHasNoErrors();
        $this->actingAs($vet)->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => '2026-10-28', 'weight_kg' => 19.5, 'outcome' => 'healthy'])->assertSessionHasNoErrors();
        $this->assertSame(0, \App\Models\Task::where('type', 'health')->count(), 'gaining weight, normal: nothing to alert');

        $this->actingAs($vet)->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => '2026-11-04', 'weight_kg' => 18.6, 'temperature_f' => 104.6, 'outcome' => 'healthy'])->assertSessionHasNoErrors();
        $c = HealthCheckup::where('checked_on', '2026-11-04')->sole();
        $this->assertSame('monitor', $c->outcome, 'not Healthy with these readings');
        $task = \App\Models\Task::where('type', 'health')->sole();
        $this->assertSame('critical', $task->priority);
        $this->assertStringContainsString('Temperature 104.6 °F (high)', $task->title);
        $this->assertStringContainsString('Weight down 0.9 KG (4.6%) since 28 Oct while pregnant', $task->title);
        $this->assertSame('open', HealthRecord::where('animal_id', $this->doe->id)->sole()->status);
        $this->assertSame('monitor', $this->doe->fresh()->health_flag);
    }
}
