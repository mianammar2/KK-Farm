<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-122: Recent Activity moved off the dashboard to the Activity Log page. */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_activity_log_lists_searches_and_hides_finance_from_farm_staff(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Log Farm', 'slug' => 'log-farm'])->id);
        Activity::create(['actor' => 'imran_ali', 'role' => 'Administrator', 'action' => 'Recorded a checkup for TD141026-06', 'audience' => 'farm', 'url' => '/goats']);
        Activity::create(['actor' => 'vet_ali', 'role' => 'Veterinarian', 'action' => 'Opened a case for MC101026-01', 'audience' => 'farm']);
        Activity::create(['actor' => 'imran_ali', 'role' => 'Administrator', 'action' => 'Recorded an expense of PKR 5,000', 'audience' => 'finance']);
        $make = fn (string $role) => User::create(['name' => "Test $role", 'email' => "$role@log.test", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
        $ceo = $make('ceo');

        $this->actingAs($ceo)->get('/activity')->assertInertia(fn ($p) => $p->component('Activity')->has('activity', 3)->where('people', ['imran_ali', 'vet_ali'])->where('more', false));
        $this->actingAs($make('caretaker'))->get('/activity')->assertInertia(fn ($p) => $p->has('activity', 2));
        $this->actingAs($ceo)->get('/activity?q=TD141026')->assertInertia(fn ($p) => $p->has('activity', 1)->where('activity.0.actor', 'imran_ali'));
        $this->actingAs($ceo)->get('/activity?who=vet_ali')->assertInertia(fn ($p) => $p->has('activity', 1));
        $this->actingAs($ceo)->get('/')->assertInertia(fn ($p) => $p->missing('activity'));
    }
}
