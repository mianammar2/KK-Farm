<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Animal;
use App\Models\Breed;
use App\Models\Department;
use App\Models\EndOfLifeReview;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\ReproLossCase;
use App\Models\Shed;
use App\Models\User;
use App\Support\Access;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-264 hotfix: regression tests for the Phase 1 findings F-02, F-03, F-04, F-05, F-06, F-08, F-16, F-26, F-27.
 * Each test asserts the spec (KK_FARMS_RBAC.md §8.3, §5) for the role that must be refused, and that the role
 * that must be allowed is not refused. Written before the fixes: they fail on the D-261 code.
 */
class RbacHotfixTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $vet;
    private User $caretaker;
    private User $finance;
    private User $ceo;
    private Animal $goat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $org = Organization::create(['name' => 'Hotfix Farm', 'slug' => 'hotfix-farm']);
        Tenant::set($org->id);
        Department::seedDefaults((string) $org->id);
        $make = fn (string $role) => User::create(['name' => "Test $role", 'email' => "$role@hotfix.test", 'password' => 'x', 'role' => $role, 'org_id' => $org->id, 'active' => true, 'department_id' => Department::forRole($role)]);
        $this->ceo = $make('ceo');
        $this->manager = $make('manager');
        $this->vet = $make('vet');
        $this->caretaker = $make('caretaker');
        $this->finance = $make('finance');
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $breed = Breed::create(['name' => 'Beetal']);
        $this->goat = Animal::create(['farm_number' => 'H-1', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $pen->id]);
        Tenant::flush();
    }

    /** F-02 (Critical): posting to the books is for the Administrator and Finance, not the Manager. */
    public function test_f02_manager_cannot_post_a_journal_or_add_an_account(): void
    {
        $this->actingAs($this->manager)->post('/finance/journals', [])->assertForbidden();
        $this->actingAs($this->manager)->post('/finance/accounts', ['name' => 'Probe', 'type' => 'expense'])->assertForbidden();
        $this->assertSame(0, Account::where('name', 'Probe')->count());
        $this->actingAs($this->finance)->post('/finance/accounts', ['name' => 'Probe', 'type' => 'expense'])->assertRedirect();
    }

    /** F-03 (High): staff records are the manager's and the Administrator's; Finance views them. */
    public function test_f03_finance_cannot_change_staff_or_attendance(): void
    {
        $this->actingAs($this->finance)->post('/employees', ['name' => 'Probe', 'role' => 'helper', 'shift' => 'day'])->assertForbidden();
        $this->actingAs($this->finance)->post('/hr/attendance', ['employee_id' => 1, 'work_on' => now()->toDateString(), 'status' => 'absent'])->assertForbidden();
        $this->actingAs($this->manager)->post('/employees', [])->assertSessionHasErrors('name'); // allowed: reaches validation
    }

    /** F-04 (High): a humane end-of-life request is the vet's or the manager's; the caretaker reports a concern instead. */
    public function test_f04_caretaker_cannot_open_an_end_of_life_review(): void
    {
        $body = ['animal_id' => $this->goat->id, 'reason' => 'other', 'details' => 'hotfix probe end of life'];
        $this->actingAs($this->caretaker)->post('/welfare/end-of-life', $body)->assertForbidden();
        $this->assertSame(0, EndOfLifeReview::count());
        $this->actingAs($this->vet)->post('/welfare/end-of-life', $body)->assertRedirect();
    }

    /** F-16 (Medium): the vet's steps follow the vet-locked action (clinical), so a person the administrator grants it can take them. */
    public function test_f16_a_person_granted_diagnosis_can_assess_and_a_manager_cannot_perform(): void
    {
        $this->actingAs($this->vet)->post('/welfare/end-of-life', ['animal_id' => $this->goat->id, 'reason' => 'other', 'details' => 'hotfix probe end of life'])->assertRedirect();
        $review = EndOfLifeReview::sole();
        $this->caretaker->forceFill(['gate_access' => ['clinical' => true]])->save();
        Tenant::flush();
        // granted: the step is allowed (it may still answer 302 or 422 on its own rules, never 403)
        $this->actingAs($this->caretaker->fresh())->post("/welfare/end-of-life/{$review->id}/assess", ['recommends' => 1, 'assessment' => 'Hotfix probe assessment text.'])->assertStatus(302);
        $this->actingAs($this->manager)->post("/welfare/end-of-life/{$review->id}/perform", ['method' => 'injection'])->assertForbidden();
    }

    /** F-05 (High): containing an abortion is the vet's or a trained person's job, not any Breeding user's. */
    public function test_f05_caretaker_cannot_contain_a_pregnancy_loss(): void
    {
        $case = ReproLossCase::create(['case_no' => 1, 'dam_id' => $this->goat->id, 'loss_type' => 'abortion', 'occurred_on' => now()->toDateString(), 'status' => 'open', 'kids_lost' => 0, 'zoonotic_suspected' => false, 'opened_by' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        $pen = Pen::first();
        $this->actingAs($this->caretaker)->post("/repro-loss/{$case->id}/contain", ['pen_id' => $pen->id])->assertForbidden();
        $this->assertSame('open', $case->fresh()->status);
        $this->actingAs($this->vet)->post("/repro-loss/{$case->id}/contain", ['pen_id' => $pen->id])->assertRedirect();
    }

    /** F-06 (High): a goat purchase request is the Administrator's or the manager's; the vet and Finance do not raise it. */
    public function test_f06_vet_and_finance_cannot_raise_a_goat_purchase(): void
    {
        $body = ['source' => 'Probe market', 'expected_head' => 3, 'budget' => 1000];
        $this->actingAs($this->vet)->post('/purchase/animals', $body)->assertForbidden();
        $this->actingAs($this->finance)->post('/purchase/animals', $body)->assertForbidden();
        $this->actingAs($this->manager)->post('/purchase/animals', $body)->assertRedirect();
    }

    /** F-08 (Medium): Settings is the Administrator's alone, even when ticked for a role or a person. */
    public function test_f08_settings_cannot_be_given_to_anyone_but_the_administrator(): void
    {
        $this->manager->forceFill(['module_access' => ['settings' => true]])->save();
        Tenant::flush();
        $this->assertFalse(Access::module($this->manager->fresh(), 'settings'));
        $this->actingAs($this->manager->fresh())->post('/settings/breeds', ['name' => 'Probe breed'])->assertForbidden();
        $this->assertTrue(Access::module($this->ceo, 'settings'));
    }

    /** F-26 (Low): a malformed record number is a not-found, not a server error. */
    public function test_f26_a_malformed_record_number_is_not_found(): void
    {
        $this->actingAs($this->ceo)->post('/weights/review/not-a-number', ['decision' => 'accepted'])->assertNotFound();
    }

    /** F-27 (Low): a missing settings record and a refused one look the same (no record-existence oracle). */
    public function test_f27_a_refused_user_gets_403_for_a_missing_setting_record(): void
    {
        $this->actingAs($this->vet)->delete('/settings/automation/codes/ZZZZ')->assertForbidden();
        $this->actingAs($this->vet)->post('/settings/fields/999999', ['label' => 'x'])->assertForbidden();
    }
}
