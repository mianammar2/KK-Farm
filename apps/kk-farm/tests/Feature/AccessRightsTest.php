<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Access;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-215: access rights by role (master) and per person (own ticks), interlinked; protected vet actions; owner never locked out. */
class AccessRightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Access Farm', 'slug' => 'access-farm'])->id);
    }

    private function user(string $role, string $name = ''): User
    {
        $name = $name ?: $role;

        return User::firstOrCreate(['email' => "$name@access.test"], ['name' => "Test $name", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current(), 'active' => true]);
    }

    public function test_role_settings_and_a_persons_own_ticks_decide_access_together(): void
    {
        $owner = $this->user('ceo');
        $ali = $this->user('caretaker', 'ali');
        $sara = $this->user('caretaker', 'sara');
        $this->actingAs($ali)->get('/hr')->assertForbidden(); // caretakers don't open Employees by default

        // Master: give caretakers Employees and take Welfare away.
        $modules = Access::roleModules(Tenant::current());
        $modules['employees'][] = 'caretaker';
        $modules['welfare'] = array_values(array_diff($modules['welfare'], ['caretaker']));
        $this->actingAs($ali)->post('/settings/access', ['modules' => $modules, 'gates' => Access::roleGates(Tenant::current())])->assertForbidden();
        $this->actingAs($owner)->post('/settings/access', ['modules' => $modules, 'gates' => Access::roleGates(Tenant::current())])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertTrue($ali->fresh()->canModule('employees'));
        $this->assertFalse($sara->fresh()->canModule('welfare'));

        // Individual: Sara gets Welfare back, loses Employees; Ali is untouched and follows the role.
        $this->actingAs($owner)->post("/settings/users/{$sara->id}/access", ['modules' => ['welfare' => true, 'employees' => false, 'goats' => null]])->assertSessionHasNoErrors();
        Tenant::flush();
        $sara = $sara->fresh();
        $this->assertSame([true, false, true], [$sara->canModule('welfare'), $sara->canModule('employees'), $sara->canModule('goats')]);
        $this->assertSame(['welfare' => true, 'employees' => false], $sara->module_access);
        $this->assertTrue($ali->fresh()->canModule('employees'));
        $this->actingAs($sara)->get('/welfare')->assertOk();
        $this->actingAs($owner)->get("/settings/users/{$sara->id}")->assertOk();
        $this->actingAs($owner)->get('/settings/access')->assertOk();
    }

    public function test_vet_decisions_stay_with_the_vet_and_the_owner_keeps_settings(): void
    {
        $owner = $this->user('ceo');
        $ali = $this->user('caretaker', 'ali');
        $this->actingAs($owner)->post("/settings/users/{$ali->id}/access", ['gates' => ['clinical' => true, 'exit_animal' => true]])->assertSessionHasNoErrors();
        Tenant::flush();
        $ali = $ali->fresh();
        $this->assertFalse($ali->passes('clinical')); // protected
        $this->assertTrue($ali->passes('exit_animal'));

        $this->actingAs($owner)->post("/settings/users/{$owner->id}/access", ['modules' => ['settings' => false]])->assertSessionHasNoErrors();
        $modules = Access::roleModules(Tenant::current());
        $modules['settings'] = [];
        $this->actingAs($owner)->post('/settings/access', ['modules' => $modules, 'gates' => []])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->actingAs($owner->fresh())->get('/settings')->assertOk();
        foreach (['/settings/breeds', '/settings/ages', '/settings/system', '/settings/users', '/apps/settings', '/reports?section=herd'] as $url) {
            $this->actingAs($owner->fresh())->get($url)->assertOk();
        }
    }
}
