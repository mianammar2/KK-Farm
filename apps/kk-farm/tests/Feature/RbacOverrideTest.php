<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D-266: the three per-person override cases of the audit plan. A person follows their role unless the Administrator ticks or
 * unticks something on their own page; the server (page rules, action registry) honours it at once, in both directions.
 */
class RbacOverrideTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'Override Farm', 'slug' => 'override-farm']);
        Tenant::set($this->org->id);
        Department::seedDefaults((string) $this->org->id);
    }

    private function person(string $role): User
    {
        return User::create(['name' => "Test $role", 'email' => "$role@override.test", 'password' => 'x', 'role' => $role, 'org_id' => $this->org->id, 'active' => true, 'department_id' => Department::forRole($role)]);
    }

    /** Case 1: an app ticked for one person opens its pages for them, and unticking closes it again. */
    public function test_an_app_given_to_one_person_opens_and_closes(): void
    {
        $fin = $this->person('finance');
        $this->actingAs($fin)->get('/goats')->assertForbidden();
        $fin->forceFill(['module_access' => ['goats' => true]])->save();
        Tenant::flush();
        $this->actingAs($fin->fresh())->get('/goats')->assertOk();
        $fin->forceFill(['module_access' => null])->save();
        Tenant::flush();
        $this->actingAs($fin->fresh())->get('/goats')->assertForbidden();
    }

    /** Case 2: a vet-locked action given to one person (by the Administrator) works for them and for nobody else of their role. */
    public function test_a_vet_locked_action_given_to_one_caretaker_works_only_for_that_person(): void
    {
        $one = $this->person('caretaker');
        $other = User::create(['name' => 'Other caretaker', 'email' => 'other@override.test', 'password' => 'x', 'role' => 'caretaker', 'org_id' => $this->org->id, 'active' => true]);
        $url = '/disease/triage'; // a clinical action: the controller checks the vet-locked right first
        $this->actingAs($one)->post($url)->assertForbidden();
        $one->forceFill(['gate_access' => ['clinical' => true]])->save();
        Tenant::flush();
        $this->assertNotSame(403, $this->actingAs($one->fresh())->post($url)->getStatusCode());
        $this->actingAs($other)->post($url)->assertForbidden();
    }

    /** Case 3: a page refused to one manager closes for them only, and removing the refusal opens it again at once. */
    public function test_a_page_refused_to_one_manager_closes_and_reopens(): void
    {
        $mgr = $this->person('manager');
        $peer = User::create(['name' => 'Peer manager', 'email' => 'peer@override.test', 'password' => 'x', 'role' => 'manager', 'org_id' => $this->org->id, 'active' => true]);
        $this->actingAs($mgr)->get('/finance')->assertOk();
        DB::table('user_page_access')->insert(['org_id' => $this->org->id, 'user_id' => $mgr->id, 'page_key' => 'finance.income_farm_expenses', 'allowed' => false, 'created_at' => now(), 'updated_at' => now()]);
        Tenant::flush();
        $this->actingAs($mgr->fresh())->get('/finance')->assertForbidden();
        $this->actingAs($peer)->get('/finance')->assertOk();
        DB::table('user_page_access')->where('user_id', $mgr->id)->delete();
        Tenant::flush();
        $this->actingAs($mgr->fresh())->get('/finance')->assertOk();
    }

    /** Step 5b: an action that used to be a list of role names in the code is now a right the Access Rights screen controls, for a role and for a person. */
    public function test_a_former_role_list_action_follows_the_access_rights_screen(): void
    {
        $ceo = $this->person('ceo');
        $mgr = $this->person('manager');
        $care = $this->person('caretaker');
        $url = '/environment/settings'; // the heat-stress limit: was ['ceo', 'vet'] inside the controller
        $this->actingAs($mgr)->post($url, ['heat_stress_thi' => 75])->assertForbidden();
        // a role: the Administrator gives it to every manager on the role screen
        $gates = \App\Support\Access::roleGates($this->org->id);
        $gates['set_heat_limit'] = [...$gates['set_heat_limit'], 'manager'];
        $this->actingAs($ceo)->post('/settings/access', ['modules' => \App\Support\Access::roleModules($this->org->id), 'gates' => $gates])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->actingAs($mgr->fresh())->post($url, ['heat_stress_thi' => 75])->assertSessionHasNoErrors();
        // a person: one caretaker only
        $this->actingAs($care)->post($url, ['heat_stress_thi' => 75])->assertForbidden();
        $care->forceFill(['gate_access' => ['set_heat_limit' => true]])->save();
        Tenant::flush();
        $this->actingAs($care->fresh())->post($url, ['heat_stress_thi' => 75])->assertSessionHasNoErrors();
    }
}
