<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\QualityCase;
use App\Models\SensorAlert;
use App\Models\Shed;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * D-266: write enforcement and the audit findings F-07, F-10, F-14, F-15, F-17, F-18, F-19.
 * Each refused case asserts 403 for the role the spec (KK_FARMS_RBAC.md §8.3) closes it to, and that the role it
 * belongs to is not refused. Run before the fixes, the refused cases fail.
 */
class RbacEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private array $u = [];
    private Animal $goat;
    private Pen $pen;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'Enforce Farm', 'slug' => 'enforce-farm']);
        Tenant::set($this->org->id);
        Department::seedDefaults((string) $this->org->id);
        foreach (['ceo', 'manager', 'vet', 'caretaker', 'feeding', 'cleaning', 'finance'] as $role) {
            $this->u[$role] = User::create(['name' => "Test $role", 'email' => "$role@enforce.test", 'password' => 'x', 'role' => $role, 'org_id' => $this->org->id, 'active' => true, 'department_id' => Department::forRole($role)]);
        }
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $this->pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $breed = Breed::create(['name' => 'Beetal']);
        $this->goat = Animal::create(['farm_number' => 'E-1', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $this->pen->id]);
        Tenant::flush();
    }

    private function reached(string $role, string $url, array $body = []): bool
    {
        Tenant::flush();

        return $this->actingAs($this->u[$role])->post($url, $body)->getStatusCode() !== 403;
    }

    /** F-07 (and the incomplete probes of D-261): view-only roles cannot write into an app they only read. */
    public function test_f07_view_only_roles_cannot_write(): void
    {
        $id = $this->goat->id;
        $cases = [ // [url, body, refused roles, allowed roles]
            ['/dairy/sessions', [], ['finance', 'vet', 'feeding', 'cleaning'], ['caretaker', 'manager']],
            ["/growth/$id/intervene", [], ['feeding', 'caretaker', 'finance', 'cleaning'], ['vet', 'manager']],
            ['/feeding/record', [], ['vet', 'caretaker', 'finance', 'cleaning'], ['feeding', 'manager']],
            ['/feeding/leftover', [], ['vet', 'caretaker'], ['feeding']],
            ['/operations/handovers', [], ['finance'], ['caretaker', 'cleaning', 'manager']],
            ['/goats/rfid/disarm', [], ['feeding', 'cleaning', 'finance'], ['caretaker', 'vet']],
            ["/goats/$id/photos", [], ['feeding', 'cleaning', 'finance'], ['caretaker', 'vet']],
            ['/fattening/selections', [], ['vet', 'feeding', 'caretaker', 'finance'], ['manager']],
            ['/environment/rounds', [], ['vet', 'finance', 'feeding'], ['caretaker', 'cleaning']],
            ['/environment/water-points', [], ['vet', 'caretaker', 'cleaning', 'finance'], ['manager']],
            ['/housing/cleanings/1/finish', [], ['vet', 'feeding', 'finance'], ['caretaker', 'cleaning']],
            ['/fodder/fields', [], ['vet', 'caretaker', 'cleaning', 'finance'], []],
        ];
        $wrong = [];
        foreach ($cases as [$url, $body, $refused, $allowed]) {
            foreach ($refused as $role) {
                if ($this->reached($role, $url, $body)) {
                    $wrong[] = "$role reached POST $url";
                }
            }
            foreach ($allowed as $role) {
                if (! $this->reached($role, $url, $body)) {
                    $wrong[] = "$role was refused POST $url";
                }
            }
        }
        $this->assertSame([], $wrong, implode("\n", $wrong));
    }

    /** F-10 (Q10): moving goats is the Administrator's, the Manager's and the Caretaker's. */
    public function test_f10_only_administrator_manager_and_caretaker_move_goats(): void
    {
        foreach (['ceo' => true, 'manager' => true, 'caretaker' => true, 'vet' => false, 'feeding' => false, 'cleaning' => false, 'finance' => false] as $role => $may) {
            $this->assertSame($may, $this->u[$role]->passes('move_goats'), "$role move_goats");
        }
    }

    /** F-15: health history follows a right the Access Rights screen controls, not a list of role names. */
    public function test_f15_health_history_follows_a_right_that_can_be_granted(): void
    {
        $this->assertFalse($this->u['caretaker']->passes('see_health_history'));
        $this->u['caretaker']->forceFill(['gate_access' => ['see_health_history' => true]])->save();
        Tenant::flush();
        $this->assertTrue($this->u['caretaker']->fresh()->passes('see_health_history'));
        $this->assertTrue($this->u['vet']->passes('see_health_history'));
        $this->assertStringNotContainsString("['ceo', 'manager', 'vet'], true) || (", file_get_contents(app_path('Http/Controllers/GoatController.php')));
    }

    /** F-14: the feeding staff see tag, pen and group, not parentage, offspring or records. */
    public function test_f14_feeding_staff_do_not_receive_parentage_offspring_or_records(): void
    {
        $kid = Animal::create(['farm_number' => 'E-2', 'sex' => 'male', 'breed_id' => $this->goat->breed_id, 'birth_date' => now()->subMonths(2), 'origin' => 'born', 'pen_id' => $this->pen->id, 'dam_id' => $this->goat->id]);
        Tenant::flush();
        $this->actingAs($this->u['feeding'])->get("/goats/{$this->goat->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('offspring', [])->where('records', []));
        $this->actingAs($this->u['feeding'])->get("/goats/{$kid->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('goat.dam', null)->where('goat.sire', null));
        $this->actingAs($this->u['manager'])->get("/goats/{$this->goat->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('offspring', 1));
    }

    /** F-17: a CAPA owner must belong to this farm business. */
    public function test_f17_a_capa_owner_from_another_farm_business_is_rejected(): void
    {
        $case = QualityCase::create(['case_no' => 1, 'source' => 'audit', 'title' => 'Probe', 'subject' => 'process', 'immediate_risk' => false, 'severity' => 'low', 'status' => 'investigating', 'opened_by' => 'Test', 'opened_on' => now()->toDateString(), 'root_cause' => 'x']);
        $other = Organization::create(['name' => 'Other Farm', 'slug' => 'other-farm']);
        $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@other.test', 'password' => 'x', 'role' => 'vet', 'org_id' => $other->id, 'active' => true]);
        Tenant::flush();
        $this->actingAs($this->u['manager'])->post("/quality/cases/{$case->id}/capa", ['kind' => 'corrective', 'action' => 'Probe action', 'owner_id' => $stranger->id, 'due_on' => now()->addDays(3)->toDateString()])
            ->assertSessionHasErrors('owner_id');
    }

    /** F-18: the cleaning staff open the alarms tab (air, temperature and water) and nothing else of the sensors. */
    public function test_f18_cleaning_sees_environment_alarms_only(): void
    {
        SensorAlert::create(['kind' => 'environment', 'severity' => 'warning', 'title' => 'Water tank low', 'status' => 'open']);
        SensorAlert::create(['kind' => 'weight_loss', 'severity' => 'warning', 'title' => 'Goat losing weight', 'status' => 'open']);
        Tenant::flush();
        $this->actingAs($this->u['cleaning'])->get('/sensors?tab=alerts')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('alerts', 1)->where('alerts.0.title', 'Water tank low')->where('devices', [])->where('readings', [])->where('out', []));
        $this->actingAs($this->u['cleaning'])->get('/sensors?tab=devices')->assertForbidden();
        $this->actingAs($this->u['cleaning'])->get('/sensors?tab=readings')->assertForbidden();
        $this->actingAs($this->u['manager'])->get('/sensors?tab=alerts')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('alerts', 2));
    }

    /** F-19: the vet has no Feed Pens or TMR Mixing Batches pages (spec §8.3). */
    public function test_f19_vet_cannot_open_feed_pens_or_tmr_batches(): void
    {
        $this->actingAs($this->u['vet'])->get('/feeding?tab=now')->assertForbidden();
        $this->actingAs($this->u['vet'])->get('/feeding?tab=tmr')->assertForbidden();
        $this->actingAs($this->u['vet'])->get('/feeding?tab=history')->assertOk();
        $this->actingAs($this->u['feeding'])->get('/feeding?tab=now')->assertOk();
    }

    /** F-12 (settled by S-1): the movements page is one page for moves (caretaker, vet view) and exits; recording an exit is its own right. */
    public function test_f12_moves_open_to_the_caretaker_but_recording_an_exit_does_not(): void
    {
        $this->actingAs($this->u['caretaker'])->get('/movements')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('canExit', false));
        $this->actingAs($this->u['vet'])->get('/movements')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('canExit', false));
        $this->actingAs($this->u['manager'])->get('/movements')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('canExit', true));
        $this->actingAs($this->u['finance'])->get('/movements')->assertForbidden(); // the sale side of an exit is in Sales for Finance
    }

    /** F-13: the feeding staff's Reports hub lists the feeding usage and waste views (no costs); a role with report sections gets none of that list. */
    public function test_f13_feeding_staff_reports_hub_lists_their_own_views(): void
    {
        $this->actingAs($this->u['feeding'])->get('/reports')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('allowed', [])->has('links', 2)->where('costs', null));
        $this->actingAs($this->u['manager'])->get('/reports')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('links', []));
    }
}
