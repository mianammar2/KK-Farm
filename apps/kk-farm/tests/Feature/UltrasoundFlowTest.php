<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Department;
use App\Models\Mating;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-298: after a mating the vet gets an ultrasound task on the check day; the scan is recorded on Breeding → Ultrasound. */
class UltrasoundFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mating_gives_the_vet_an_ultrasound_task_that_the_scan_closes(): void
    {
        $this->withoutVite();
        $org = Organization::create(['name' => 'Scan Farm', 'slug' => 'scan-farm']);
        $org->forceFill(['pregnancy_check_days' => 35])->save();
        Tenant::set($org->id);
        Department::seedDefaults((string) $org->id);
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $breed = Breed::create(['name' => 'Makhi Cheeni', 'default_gestation_days' => 150]);
        $mk = fn ($n, $sex) => Animal::create(['farm_number' => $n, 'sex' => $sex, 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        [$doe, $other, $buck] = [$mk('MC-101026P-14', 'female'), $mk('MC-101026P-15', 'female'), $mk('MC-221026P-01', 'male')];
        $user = fn ($role) => User::create(['name' => "Test $role", 'email' => "$role@scan.test", 'password' => 'x', 'role' => $role, 'org_id' => $org->id, 'active' => true, 'department_id' => Department::forRole($role)]);
        [$manager, $vet] = [$user('manager'), $user('vet')];

        $mated = now()->subDays(36)->toDateString();
        $this->actingAs($manager)->post('/breeding/matings', ['dam_id' => $doe->id, 'sire_id' => $buck->id, 'mated_on' => $mated, 'method' => 'natural', 'heat_observed' => 'yes'])->assertSessionHasNoErrors();
        $task = Task::where('animal_id', $doe->id)->where('type', 'pregnancy_check')->sole();
        $this->assertSame('Ultrasound: MC-101026P-14 (day 35 after mating)', $task->title);
        $this->assertSame(now()->subDays(1)->toDateString(), $task->due_on->toDateString());
        $this->assertSame(Department::where('name', 'Veterinary')->value('id'), $task->department_id);

        $this->actingAs($vet)->get('/breeding?tab=ultrasound')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('ultrasound', 1)->where('ultrasound.0.dam', 'MC-101026P-14')->where('ultrasound.0.isDue', true)->where('ultrasound.0.day', 36));

        $m = Mating::where('dam_id', $doe->id)->sole();
        $this->actingAs($manager)->post("/breeding/matings/{$m->id}/check", ['ultrasound_status' => 'PCF', 'checked_on' => now()->toDateString()])->assertForbidden(); // the vet's finding
        $this->actingAs($vet)->post("/breeding/matings/{$m->id}/check", ['ultrasound_status' => 'PCF', 'checked_on' => now()->toDateString(), 'fetuses' => 1, 'viability' => 'HBD'])->assertSessionHasNoErrors();
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame('pregnant', $m->fresh()->status);
        $this->actingAs($vet)->get('/breeding?tab=ultrasound')->assertInertia(fn (AssertableInertia $p) => $p->has('ultrasound', 0));

        // a doe with no mating on record is added for ultrasound by the vet
        $this->actingAs($manager)->post('/breeding/ultrasound', ['dam_id' => $other->id])->assertForbidden();
        $this->actingAs($vet)->post('/breeding/ultrasound', ['dam_id' => $other->id, 'days' => 50])->assertSessionHasNoErrors();
        $added = Mating::where('dam_id', $other->id)->sole();
        $this->assertSame(['checkup', 'open', now()->subDays(50)->toDateString()], [$added->source, $added->status, $added->mated_on->toDateString()]);
        $this->actingAs($vet)->post('/breeding/ultrasound', ['dam_id' => $other->id])->assertSessionHasErrors('dam_id'); // already listed
        $this->actingAs($vet)->get('/breeding?tab=ultrasound')->assertInertia(fn (AssertableInertia $p) => $p->has('ultrasound', 1)->where('ultrasound.0.isDue', true));
    }
}
