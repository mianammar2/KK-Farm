<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-294: every recorded mating is listed on Breeding → Mating Records, for the people who may see breeding. */
class MatingRecordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_recorded_mating_appears_in_the_mating_records_sheet(): void
    {
        $this->withoutVite();
        $org = Organization::create(['name' => 'Mating Farm', 'slug' => 'mating-farm']);
        Tenant::set($org->id);
        Department::seedDefaults((string) $org->id);
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $breed = Breed::create(['name' => 'Makhi Cheeni', 'default_gestation_days' => 150]);
        $doe = Animal::create(['farm_number' => 'MC-101026P-14', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $pen->id]);
        $buck = Animal::create(['farm_number' => 'MC-221026P-01', 'sex' => 'male', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $pen->id]);
        $make = fn (string $role) => User::create(['name' => "Test $role", 'email' => "$role@mating.test", 'password' => 'x', 'role' => $role, 'org_id' => $org->id, 'active' => true, 'department_id' => Department::forRole($role)]);
        $manager = $make('manager');

        $this->actingAs($manager)->post('/breeding/matings', ['dam_id' => $doe->id, 'sire_id' => $buck->id, 'mated_on' => now()->subDay()->toDateString(), 'method' => 'natural', 'heat_observed' => 'yes'])->assertSessionHasNoErrors();

        $this->actingAs($manager)->get('/breeding?tab=matings')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('matingRecords', 1)
            ->where('matingRecords.0.dam', 'MC-101026P-14')->where('matingRecords.0.sire', 'MC-221026P-01')
            ->where('matingRecords.0.method', 'Natural')->where('matingRecords.0.by', 'Test manager')
            ->where('matingRecords.0.mated', now()->subDay()->format('d/m/Y')));
        // D-295: a doe found pregnant at a checkup, or pregnant when bought, is a pregnancy, not one of the farm's matings
        \App\Models\Mating::create(['dam_id' => $doe->id, 'sire_id' => null, 'mated_on' => now()->subDays(40), 'method' => 'natural', 'status' => 'pregnant', 'recorded_by' => 'Test', 'notes' => 'Found pregnant at the health checkup on 21/10/2026.', 'source' => 'checkup']);
        \App\Models\Mating::create(['dam_id' => $doe->id, 'sire_id' => null, 'mated_on' => now()->subDays(70), 'method' => 'natural', 'status' => 'pregnant', 'recorded_by' => 'Test', 'notes' => 'Pregnant when she came to the farm.', 'source' => 'arrival']);
        $this->actingAs($manager)->get('/breeding?tab=matings')->assertInertia(fn (AssertableInertia $p) => $p->has('matingRecords', 1)->where('matingRecords.0.sire', 'MC-221026P-01'));
        // the same list whatever tab the page opened on (tabs switch without a reload)
        $this->actingAs($manager)->get('/breeding?tab=heat')->assertInertia(fn (AssertableInertia $p) => $p->has('matingRecords', 1));
        // people without the Breeding app see none of it
        $this->actingAs($make('finance'))->get('/breeding?tab=matings')->assertForbidden();
    }
}
