<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthCheckup;
use App\Models\Mating;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RecordRevision;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** D-099: each person corrects their own records (the administrator anyone's), with a kept trail; checkup follow-ups. */
class CorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Animal $doe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Fix Farm', 'slug' => 'fix-farm'])->id);
        $breed = Breed::create(['name' => 'Teddy', 'default_gestation_days' => 150]);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'KKF-BDU-01'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->doe = Animal::create(['farm_number' => 'TD141026-06', 'sex' => 'female', 'breed_id' => $breed->id, 'origin' => 'purchased', 'pen_id' => $pen->id, 'birth_date' => now()->subYears(2)])->refresh();
    }

    private function user(string $role, string $name): User
    {
        return User::firstOrCreate(['email' => "$name@fix.test"], ['name' => $name, 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_the_vet_corrects_a_wrong_date_and_missing_temperature_and_everything_follows(): void
    {
        $vet = $this->user('vet', 'ammar');
        $this->actingAs($vet)->post('/health/checkups', ['animal_id' => $this->doe->id, 'checked_on' => now()->subDays(1)->toDateString(), 'weight_kg' => 18.6,
            'ultrasound' => 'pregnant', 'days_pregnant' => 40, 'outcome' => 'healthy', 'follow_up_on' => now()->addDays(6)->toDateString(), 'follow_up_note' => 'Confirm pregnancy'])->assertSessionHasNoErrors();
        $c = HealthCheckup::sole();
        $task = Task::findOrFail($c->follow_up_task_id);
        $this->assertSame(['Recheck TD141026-06: Confirm pregnancy', now()->addDays(6)->toDateString()], [$task->title, $task->due_on->toDateString()]);

        // The real checkup was on 21 Oct, 102.4 °F, 60 days pregnant; recheck a day later.
        $this->actingAs($vet)->post("/health/checkups/{$c->id}", ['checked_on' => '2026-10-21', 'temperature_f' => 102.4, 'weight_kg' => 18.6, 'ultrasound' => 'pregnant',
            'days_pregnant' => 60, 'outcome' => 'healthy', 'follow_up_on' => '2026-11-04', 'follow_up_note' => 'Confirm pregnancy', 'reason' => 'Wrong date entered'])->assertSessionHasNoErrors();

        $c->refresh();
        $this->assertSame(['2026-10-21', 102.4, 60], [$c->checked_on->toDateString(), $c->temperature_f, $c->days_pregnant]);
        $mating = Mating::sole();
        $this->assertSame(['2026-08-22', '2027-01-19'], [$mating->mated_on->toDateString(), $mating->expected_kidding_on->toDateString()], 'the pregnancy follows the corrected date and days');
        $this->assertSame('2026-10-21', WeightRecord::sole()->measured_on->toDateString(), 'the weighing follows');
        $this->assertSame('2026-11-04', $task->fresh()->due_on->toDateString(), 'the recheck task moves');
        $rev = RecordRevision::sole();
        $this->assertSame(['ammar', 'Wrong date entered'], [$rev->changed_by, $rev->reason]);
        $this->assertArrayHasKey('Temperature °F', $rev->changes);
        $this->assertSame([null, '102.4'], [$rev->changes['Temperature °F'][0], (string) $rev->changes['Temperature °F'][1]]);
        $this->assertStringContainsString('Checkup corrected by ammar', $this->doe->events()->where('type', 'edit')->sole()->summary);

        // Another vet cannot change ammar's checkup; the administrator can.
        $this->actingAs($this->user('vet', 'other-vet'))->post("/health/checkups/{$c->id}", ['checked_on' => '2026-10-21', 'outcome' => 'sick'])->assertForbidden();
        $this->actingAs($this->user('ceo', 'imran'))->post("/health/checkups/{$c->id}", ['checked_on' => '2026-10-21', 'temperature_f' => 102.4, 'weight_kg' => 18.6, 'ultrasound' => 'pregnant', 'days_pregnant' => 60, 'outcome' => 'monitor'])->assertSessionHasNoErrors();
        $this->assertSame('monitor', $c->fresh()->outcome);

        $this->expectExceptionMessage('Corrections are kept');
        DB::table('record_revisions')->delete();
    }

    public function test_a_weighing_is_corrected_by_whoever_weighed_or_the_administrator(): void
    {
        $care = $this->user('caretaker', 'worker-1');
        $this->actingAs($care)->post('/weights', ['animal_id' => $this->doe->id, 'weight_kg' => 81, 'measured_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $w = WeightRecord::sole();
        $this->actingAs($this->user('caretaker', 'worker-2'))->post("/weights/{$w->id}", ['measured_on' => now()->toDateString(), 'weight_kg' => 18])->assertForbidden();
        $this->actingAs($care)->post("/weights/{$w->id}", ['measured_on' => now()->toDateString(), 'weight_kg' => 18, 'reason' => 'Typed 81 instead of 18'])->assertSessionHasNoErrors();
        $this->assertSame(18.0, (float) $w->fresh()->weight_kg);
        $this->assertEquals([81, 18], [(float) RecordRevision::sole()->changes['Weight KG'][0], (float) RecordRevision::sole()->changes['Weight KG'][1]]);
        $this->actingAs($this->user('ceo', 'imran'))->post("/weights/{$w->id}", ['measured_on' => now()->toDateString(), 'weight_kg' => 18.5])->assertSessionHasNoErrors();
    }
}
