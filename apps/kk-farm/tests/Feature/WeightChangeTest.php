<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Organization;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-216: Dashboard weight gain and loss per goat. */
class WeightChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_gain_and_loss_per_goat_since_previous_period_and_first(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Gain Farm', 'slug' => 'gain-farm'])->id);
        $breed = Breed::create(['name' => 'Teddy']);
        $goat = fn ($tag) => Animal::create(['farm_number' => $tag, 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'status' => 'active']);
        $a = $goat('TD141026-01');
        $b = $goat('TD141026-02');
        $goat('TD141026-03'); // never weighed
        $w = fn ($g, $kg, $days) => WeightRecord::create(['animal_id' => $g->id, 'weight_kg' => $kg, 'measured_on' => now()->subDays($days)->toDateString(), 'measured_by' => 'x']);
        $w($a, 15, 40); $w($a, 16, 14); $w($a, 17.4, 7);
        $w($b, 22.4, 7); $w($b, 20.4, 0);
        $user = User::create(['name' => 'Boss', 'email' => 'boss@gain.test', 'password' => 'x', 'role' => 'ceo', 'org_id' => Tenant::current()]);

        $this->actingAs($user)->get('/dashboard/weight-change?since='.now()->subDays(20)->toDateString())->assertInertia(fn (AssertableInertia $p) => $p
            ->where('rows.0.sincePrev', ['kg' => 1.4, 'days' => 7, 'adg' => 200])
            ->where('rows.0.period.kg', 2.4)->where('rows.0.base.kg', 15) // 15 KG was its weight on the start date
            ->where('rows.0.total', ['kg' => 2.4, 'days' => 33, 'adg' => 73])
            ->where('rows.1.sincePrev', ['kg' => -2, 'days' => 7, 'adg' => -286])
            ->where('rows.2.latest', null)
            ->where('kpi.gaining', 1)->where('kpi.losing', 1)->where('kpi.weighed', 2));
    }
}
