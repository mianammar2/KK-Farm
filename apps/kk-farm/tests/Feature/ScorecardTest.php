<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Lactation;
use App\Models\Mating;
use App\Models\MilkRecord;
use App\Models\Organization;
use App\Models\User;
use App\Support\Lifecycle;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** D-233: the CEO scorecard counts from the same facts; overlapping statuses are counted apart from the herd total. */
class ScorecardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_scorecard_counts_roles_and_overlapping_statuses(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Score Farm', 'slug' => 'score-farm'])->id);
        $owner = User::create(['email' => 'o@score.test', 'name' => 'Owner', 'password' => 'x', 'role' => 'ceo', 'org_id' => Tenant::current(), 'active' => true]);
        $breed = Breed::create(['name' => 'Teddy']);
        $mk = fn (string $tag, string $sex, int $months, array $more = []) => Animal::create($more + ['farm_number' => $tag, 'sex' => $sex, 'breed_id' => $breed->id, 'birth_date' => now()->subMonths($months), 'origin' => 'purchased', 'status' => 'active']);
        $doe = $mk('D1', 'female', 30, ['animal_purpose' => 'breeding', 'teeth' => 'T06']);
        $mk('D2', 'female', 30, ['animal_purpose' => 'breeding']);
        $mk('B1', 'male', 30, ['animal_purpose' => 'breeding']);
        $mk('K1', 'female', 1);
        Mating::create(['dam_id' => $doe->id, 'mated_on' => now()->subDays(40)->toDateString(), 'expected_kidding_on' => now()->addDays(110)->toDateString(), 'status' => 'pregnant', 'method' => 'natural', 'recorded_by' => 'x']);
        $l = Lactation::create(['animal_id' => $doe->id, 'number' => 1, 'started_on' => now()->subDays(90), 'source' => 'kidding', 'recorded_by' => 'x']);
        MilkRecord::create(['id' => (string) Str::uuid(), 'animal_id' => $doe->id, 'lactation_id' => $l->id, 'milked_on' => now()->toDateString(), 'session' => 'morning', 'litres' => 2.0, 'discarded' => false, 'recorded_by' => 'x']);
        Tenant::flush();
        Lifecycle::recordAll();
        Tenant::flush();

        $this->actingAs($owner)->get('/dashboard/scorecard')->assertOk()->assertInertia(function ($p) {
            $p->component('Scorecard')->where('groups', function ($groups) {
                $g = collect($groups)->keyBy('key');
                $item = fn (string $group, string $label) => collect($g[$group]['items'])->firstWhere('label', $label)['value'] ?? null;

                return $item('population', 'Total') === 4 && $item('stock', 'BD · Breeding Does') === 2 && $item('stock', 'BB · Breeding Bucks') === 1
                    && $item('repro', 'Pregnant') === 1 && $item('dairy', 'Milking') === 1 && $item('teeth', 'T06 · 6 Permanent Incisors') === 1
                    && $item('age', 'KID · Kid') === 1 && (float) $item('dairy', 'Milk, last 7 days') === 2.0 && isset($g['money']);
            });
        });
        // The pregnant and milking doe opens from either number; she is one goat in the herd.
        $this->actingAs($owner)->get('/goats?show=life_breeding_pregnant')->assertInertia(fn ($p) => $p->where('showLabels.life_breeding_pregnant', 'Breeding: Pregnant')
            ->where('goats', fn ($goats) => collect($goats)->filter(fn ($x) => in_array('life_breeding_pregnant', $x['tags'], true) && in_array('life_lactation_milking', $x['tags'], true))->count() === 1));
    }
}
