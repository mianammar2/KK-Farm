<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-181: attendance register, leave requests and the attendance report. */
class HrTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_and_approved_leave_fill_the_register(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'HR Farm', 'slug' => 'hr-farm'])->id);
        $a = Employee::create(['code' => 'E1', 'name' => 'Ali', 'role' => 'Caretaker', 'shift' => 'Morning', 'is_active' => true]);
        $b = Employee::create(['code' => 'E2', 'name' => 'Bilal', 'role' => 'Feeder', 'shift' => 'Evening', 'is_active' => true]);
        $manager = User::create(['name' => 'Manager', 'email' => 'm@hr.test', 'password' => 'x', 'role' => 'manager', 'org_id' => Tenant::current()]);
        $caretaker = User::create(['name' => 'Care', 'email' => 'c@hr.test', 'password' => 'x', 'role' => 'caretaker', 'org_id' => Tenant::current()]);

        $this->actingAs($caretaker)->get('/hr')->assertForbidden(); // Employees module: owner and manager
        $this->actingAs($manager)->post('/hr/leave', ['employee_id' => $b->id, 'from_on' => now()->toDateString(), 'to_on' => now()->addDay()->toDateString(), 'kind' => 'sick'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post('/hr/leave/'.LeaveRequest::sole()->id, ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post('/hr/leave/'.LeaveRequest::sole()->id, ['decision' => 'rejected'])->assertStatus(422);

        $this->actingAs($manager)->post('/hr/attendance', ['employee_id' => $a->id, 'work_on' => now()->toDateString(), 'status' => 'present', 'check_in' => '07:00'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post('/hr/attendance/all-present', ['work_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(['present', 'leave'], [Attendance::where('employee_id', $a->id)->value('status'), Attendance::where('employee_id', $b->id)->value('status')]);
        $this->actingAs($manager)->get('/hr')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('register', fn ($r) => collect($r)->firstWhere('name', 'Ali')['in'] === '07:00:00' && collect($r)->firstWhere('name', 'Bilal')['onLeave'] === true)
            ->where('report', fn ($r) => collect($r)->firstWhere('name', 'Bilal')['leave'] === 1));
    }
}
