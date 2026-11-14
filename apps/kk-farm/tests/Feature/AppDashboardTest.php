<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Organization;
use App\Models\User;
use App\Support\AppDashboards;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-257: every app opens on its own dashboard. */
class AppDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_app_has_a_dashboard_for_the_roles_that_open_it(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Dash Farm', 'slug' => 'dash-farm'])->id);
        \App\Models\Department::seedDefaults((string) Tenant::current());
        $owner = User::create(['name' => 'Owner', 'email' => 'o@dash.test', 'password' => 'x', 'role' => 'ceo', 'org_id' => Tenant::current()]);
        $breed = Breed::create(['name' => 'Beetal']);
        foreach (range(1, 3) as $i) {
            Animal::create(['farm_number' => "D-$i", 'sex' => $i % 2 ? 'female' : 'male', 'breed_id' => $breed->id, 'birth_date' => now()->subMonths(10 * $i), 'origin' => 'purchased', 'status' => 'active']);
        }
        foreach (array_keys(AppDashboards::APPS) as $app) {
            $this->actingAs($owner)->get("/dashboard/app/{$app}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('AppDashboard')->where('app', $app)->has('kpis')->has('charts')->has('alerts'));
        }
        // numbers that are zero are not shown; a goat count is
        $this->actingAs($owner)->get('/dashboard/app/livestock')->assertInertia(fn ($p) => $p
            ->where('kpis', fn ($k) => collect($k)->firstWhere('label', 'Goats on the farm')['value'] === 3 && collect($k)->every(fn ($x) => $x['value'] !== 0)));

        $cleaner = User::create(['name' => 'Cleaner', 'email' => 'c@dash.test', 'password' => 'x', 'role' => 'cleaning', 'org_id' => Tenant::current()]);
        $this->actingAs($cleaner)->get('/dashboard/app/livestock')->assertForbidden();
        $this->actingAs($cleaner)->get('/dashboard/app/housing')->assertOk();
        $this->actingAs($owner)->get('/dashboard/app/nothing')->assertNotFound();
    }
}
