<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-118: the dashboard's herd by age band and gender, and by breed; each number opens those goats. */
class HerdByAgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_groups_the_herd_by_age_and_gender(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Age Farm', 'slug' => 'age-farm'])->id);
        $mc = Breed::create(['name' => 'Makhi Cheeni', 'default_gestation_days' => 150]);
        $td = Breed::create(['name' => 'Teddy', 'default_gestation_days' => 150]);
        $make = fn (string $tag, string $sex, ?int $months, Breed $b, array $extra = []) => Animal::create($extra + ['farm_number' => $tag, 'sex' => $sex, 'breed_id' => $b->id, 'origin' => 'purchased',
            'birth_date' => $months === null ? null : now()->subMonths($months)->toDateString()]);
        $make('K1', 'female', 1, $mc);
        $make('G1', 'female', 8, $mc, ['health_flag' => 'sick']);
        $make('G2', 'male', 8, $td);
        $make('A1', 'female', 30, $td);
        $make('A2', 'female', 30, $td);
        $make('U1', 'male', null, $mc);
        $user = User::create(['name' => 'Owner', 'email' => 'owner@age.test', 'password' => 'x', 'role' => 'ceo', 'org_id' => Tenant::current()]);

        $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page
            ->where('herdAge.total', 6)->where('herdAge.female', 4)->where('herdAge.male', 2)
            // D-226: one row per age stage with goats (K1 1 month = KID, G1/G2 8 months = GRW, A1/A2 30 months = ADT-T08), then no age.
            ->where('herdAge.rows.0', fn ($r) => $r['key'] === 'stage_KID' && $r['code'] === 'KID' && $r['group'] === 'kid' && $r['female'] === 1 && $r['total'] === 1)
            ->where('herdAge.rows.1', fn ($r) => $r['key'] === 'stage_GRW' && $r['female'] === 1 && $r['male'] === 1 && $r['range'] === '2 to 14 months · Milk Teeth')
            ->where('herdAge.rows.2', fn ($r) => $r['key'] === 'stage_ADT-T08' && $r['female'] === 2 && $r['total'] === 2)
            ->where('herdAge.rows.3.key', 'no_stage')->where('herdAge.rows.3.male', 1)
            // D-123: by classification; A1 and A2 are adult does, so suggested Breeding Female (Doe).
            ->where('herdAge.classes', fn ($c) => ($row = collect($c)->firstWhere('key', 'class_breeding_female')) && $row['label'] === 'Breeding Female (Doe)' && $row['total'] === 2 && $row['local'] === 'Nasli Bakri'));
        $this->actingAs($user)->get('/goats?show=class_breeding_female')->assertInertia(fn ($page) => $page->where('showLabels.class_breeding_female', 'Breeding Female (Doe)')
            ->where('goats', fn ($goats) => collect($goats)->filter(fn ($g) => in_array('class_breeding_female', $g['tags'], true))->count() === 2));

        $this->actingAs($user)->get('/goats?show=stage_ADT-T08_female')->assertInertia(fn ($page) => $page->where('showLabels.stage_ADT-T08_female', 'ADT-T08 · Adult · Females')
            ->where('goats', fn ($goats) => collect($goats)->filter(fn ($g) => in_array('stage_ADT-T08_female', $g['tags'], true))->count() === 2));

        // The count opens exactly those goats.
        $this->actingAs($user)->get('/goats?show=adult_female')->assertInertia(fn ($page) => $page->where('goats', fn ($goats) => collect($goats)->filter(fn ($g) => in_array('adult_female', $g['tags'], true))->pluck('number')->sort()->values()->all() === ['A1', 'A2'])
            ->where('showLabels.adult_female', 'Adult Females'));
    }
}
