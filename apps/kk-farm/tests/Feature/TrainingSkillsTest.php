<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\StaffSkill;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-210: App 14 training compliance by job; skills and authorisations with evidence, granted by the owner or manager. */
class TrainingSkillsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Staff Farm', 'slug' => 'staff-farm'])->id);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@staff.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_compliance_follows_the_training_each_job_needs_and_authorisations_need_evidence(): void
    {
        $e = Employee::create(['code' => 'E01', 'name' => 'Imran', 'role' => 'Caretaker', 'shift' => 'Morning', 'is_active' => true]);
        $train = fn ($topic, $extra = []) => $this->actingAs($this->user('manager'))->post('/hr/training', $extra + ['employee_ids' => [$e->id], 'topic' => $topic, 'trained_on' => now()->subMonth()->toDateString()]);
        $train('handling')->assertSessionHasNoErrors();
        $train('biosecurity', ['expires_on' => now()->subDay()->toDateString(), 'trained_on' => now()->subYear()->toDateString()])->assertSessionHasNoErrors(); // expired

        $page = fn () => $this->actingAs($this->user('manager'))->get('/hr/training');
        $page()->assertInertia(fn (AssertableInertia $p) => $p->where('matrix.0.compliant', false)->where('matrix.0.cells.biosecurity.state', 'expired')->where('matrix.0.cells.first_aid.state', 'missing')->where('kpi.compliance', 0));
        $train('biosecurity')->assertSessionHasNoErrors();
        $train('first_aid')->assertSessionHasNoErrors();
        $page()->assertInertia(fn (AssertableInertia $p) => $p->where('matrix.0.compliant', true)->where('kpi.compliance', 100));
        $this->actingAs($this->user('caretaker'))->post('/hr/training', ['employee_ids' => [$e->id], 'topic' => 'ppe', 'trained_on' => now()->toDateString()])->assertForbidden();

        $skill = fn ($role, $extra = []) => $this->actingAs($this->user($role))->post('/hr/skills', $extra + ['employee_id' => $e->id, 'skill' => 'drug_administration', 'level' => 'competent', 'assessed_on' => now()->toDateString()]);
        $skill('vet', ['evidence' => 'Assessed'])->assertForbidden(); // the Employees app is the owner's and manager's
        $skill('manager')->assertSessionHasErrors('evidence');
        $skill('manager', ['evidence' => 'Assessed by Dr. Ahmed, 10 doses observed'])->assertSessionHasNoErrors();
        $skill('manager', ['skill' => 'milker'])->assertSessionHasNoErrors();
        $s = StaffSkill::where('skill', 'drug_administration')->sole();
        $this->assertTrue($s->valid());
        $this->actingAs($this->user('manager'))->post("/hr/skills/{$s->id}/revoke", ['reason' => 'Left the dairy team'])->assertSessionHasNoErrors();
        $this->assertFalse($s->fresh()->valid());
        $this->assertSame(['drug_administration', 'milker'], StaffSkill::orderBy('id')->pluck('skill')->all()); // revoked, never deleted
    }
}
