<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Kidding;
use App\Models\Organization;
use App\Models\User;
use App\Support\Designations;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-230: sex-wise role codes are generated from the goat's facts; castration makes a wether and blocks it as a sire. */
class DesignationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_follow_the_facts_and_a_castrated_male_is_never_a_sire(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Role Farm', 'slug' => 'role-farm'])->id);
        $vet = User::create(['email' => 'v@role.test', 'name' => 'Vet', 'password' => 'x', 'role' => 'vet', 'org_id' => Tenant::current(), 'active' => true]);
        $caretaker = User::create(['email' => 'c@role.test', 'name' => 'Care', 'password' => 'x', 'role' => 'caretaker', 'org_id' => Tenant::current(), 'active' => true]);
        $breed = Breed::create(['name' => 'Makhi Cheeni']);
        $goat = fn (string $tag, string $sex, int $months, array $more = []) => Animal::create(['farm_number' => $tag, 'sex' => $sex, 'breed_id' => $breed->id, 'birth_date' => now()->subMonths($months), 'origin' => 'purchased', 'status' => 'active'] + $more);

        $this->assertSame(['BK'], Designations::codes($goat('BK1', 'male', 1)));
        $this->assertSame(['DK'], Designations::codes($goat('DK1', 'female', 1)));
        $this->assertSame(['GD'], Designations::codes($goat('GD1', 'female', 8)));
        // UC-04: male, 10 months, intact, selected for genetics: Grower + Replacement Buck.
        $rb = $goat('RB1', 'male', 10, ['animal_purpose' => 'replacement']);
        $this->assertSame(['GRW', ['RB']], [$rb->ageStageCode(), Designations::codes($rb)]);
        $this->assertSame(['FM'], Designations::codes($goat('FM1', 'male', 9, ['animal_purpose' => 'fattening'])));
        $this->assertSame(['FF'], Designations::codes($goat('FF1', 'female', 9, ['animal_purpose' => 'meat'])));
        $this->assertSame(['CF'], Designations::codes($goat('CF1', 'female', 40, ['animal_class' => 'cull'])));

        // A breeding doe with kids and in milk is BD + Proven Dam + Dairy Doe at once (UC-10: statuses do not conflict).
        $buck = $goat('BB1', 'male', 30, ['animal_purpose' => 'breeding']);
        $doe = $goat('BD1', 'female', 30, ['animal_purpose' => 'breeding']);
        $this->assertSame(['BD'], Designations::codes($doe));
        Kidding::create(['dam_id' => $doe->id, 'kidded_on' => now()->subDays(20), 'born_alive' => 2, 'stillborn' => 0, 'recorded_by' => 'Vet']);
        $goat('KID1', 'female', 0, ['dam_id' => $doe->id, 'sire_id' => $buck->id, 'origin' => 'born_on_farm']);
        Tenant::flush();
        $this->assertSame(['BD', 'PD', 'DD'], Designations::codes($doe->fresh()));
        $this->assertSame(['BB', 'PS'], Designations::codes($buck->fresh()));

        // UC-06: an approved castration recorded by the vet: wether, breeding purpose cleared, sire blocked.
        $this->actingAs($caretaker)->post("/goats/{$buck->id}/castration", ['castrated_on' => now()->toDateString(), 'method' => 'burdizzo'])->assertForbidden();
        $this->actingAs($vet)->post("/goats/{$buck->id}/castration", ['castrated_on' => now()->toDateString(), 'method' => 'burdizzo'])->assertSessionHasNoErrors();
        $buck->refresh();
        Tenant::flush();
        $this->assertSame(['wether', null, ['WTH']], [$buck->animal_class, $buck->animal_purpose, Designations::codes($buck)]);
        $this->assertTrue(\App\Models\HealthRecord::where('animal_id', $buck->id)->where('kind', 'procedure')->whereNotNull('next_due_on')->exists(), 'recovery check due');
        $this->actingAs($vet)->post("/goats/{$buck->id}/castration", ['castrated_on' => now()->toDateString(), 'method' => 'band'])->assertSessionHasErrors('castrated_on');
        $this->actingAs($vet)->post("/goats/{$doe->id}/castration", ['castrated_on' => now()->toDateString(), 'method' => 'band'])->assertSessionHasErrors('castrated_on');
        $this->actingAs($vet)->post('/breeding/matings', ['dam_id' => $doe->id, 'sire_id' => $buck->id, 'mated_on' => now()->toDateString(), 'method' => array_key_first(\App\Models\Mating::METHODS)])->assertSessionHasErrors('sire_id');
        // Choosing a breeding class by hand cannot undo it.
        $buck->update(['animal_class' => 'breeding_male']);
        $this->assertSame('wether', $buck->fresh()->animal_class);

        $this->actingAs($vet)->get('/')->assertInertia(fn ($p) => $p->where('herdAge.roles', fn ($r) => collect($r)->firstWhere('code', 'WTH')['total'] === 1 && collect($r)->firstWhere('code', 'PD')['main'] === false));
        $this->actingAs($vet)->get("/goats/{$doe->id}")->assertInertia(fn ($p) => $p->where('roleLabels', ['BD' => 'Breeding Doe', 'PD' => 'Proven Dam', 'DD' => 'Dairy Doe'])->where('castration', null));
        $this->actingAs($vet)->get('/goats?show=role_PD')->assertInertia(fn ($p) => $p->where('showLabels.role_PD', 'PD · Proven Dam'));
    }
}
