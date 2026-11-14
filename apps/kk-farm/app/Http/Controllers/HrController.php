<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-181: Employees & HR: today's attendance register (present, half day, absent, on leave; check-in and check-out),
 * the shift roster, leave requests (owner or manager approves; an approved leave fills the register as On Leave) and a
 * 30-day attendance report.
 */
class HrController extends Controller
{
    public function index(Request $request): Response
    {
        $day = Carbon::parse($request->query('day', now()->toDateString()));
        $employees = Employee::where('is_active', true)->orderBy('shift')->orderBy('name')->get();
        $marks = Attendance::where('work_on', $day->toDateString())->get()->keyBy('employee_id');
        $onLeave = LeaveRequest::where('status', 'approved')->where('from_on', '<=', $day)->where('to_on', '>=', $day)->pluck('employee_id')->flip();
        $from = now()->subDays(29)->startOfDay();
        $month = Attendance::where('work_on', '>=', $from)->get()->groupBy('employee_id');

        return Inertia::render('Hr', [
            'day' => $day->toDateString(), 'dayLabel' => $day->format('d/m/Y'),
            'register' => $employees->map(fn (Employee $e) => [
                'id' => $e->id, 'name' => $e->name, 'code' => $e->code, 'role' => $e->role, 'shift' => $e->shift,
                'status' => $marks[$e->id]->status ?? (isset($onLeave[$e->id]) ? 'leave' : null), 'in' => $marks[$e->id]->check_in ?? null, 'out' => $marks[$e->id]->check_out ?? null,
                'onLeave' => isset($onLeave[$e->id]),
            ]),
            'leave' => LeaveRequest::with('employee')->where('to_on', '>=', now()->subDays(60))->orderByRaw("case status when 'pending' then 0 else 1 end")->orderByDesc('from_on')->get()->map(fn (LeaveRequest $l) => [
                'id' => $l->id, 'employee' => $l->employee?->name, 'from' => $l->from_on->format('d/m'), 'to' => $l->to_on->format('d/m'), 'days' => $l->days(), 'kind' => $l->kind,
                'reason' => $l->reason, 'status' => $l->status, 'by' => $l->requested_by, 'decidedBy' => $l->decided_by,
            ]),
            'report' => $employees->map(fn (Employee $e) => [
                'name' => $e->name, 'shift' => $e->shift,
                'present' => ($month[$e->id] ?? collect())->where('status', 'present')->count(), 'half' => ($month[$e->id] ?? collect())->where('status', 'half_day')->count(),
                'absent' => ($month[$e->id] ?? collect())->where('status', 'absent')->count(), 'leave' => ($month[$e->id] ?? collect())->where('status', 'leave')->count(),
            ]),
            'employees' => $employees->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->name]),
            'options' => ['status' => Attendance::STATUS, 'kinds' => LeaveRequest::KINDS],
        ]);
    }

    public function mark(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'], 'work_on' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::in(array_keys(Attendance::STATUS))], 'check_in' => ['nullable', 'date_format:H:i'], 'check_out' => ['nullable', 'date_format:H:i', 'after:check_in'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);
        Attendance::updateOrCreate(['employee_id' => $data['employee_id'], 'work_on' => $data['work_on']], $data + ['recorded_by' => $request->user()->name]);

        return back();
    }

    /** Everyone not marked yet on that day is marked present (one tap at the start of the shift). */
    public function allPresent(Request $request): RedirectResponse
    {
        $data = $request->validate(['work_on' => ['required', 'date', 'before_or_equal:today']]);
        $onLeave = LeaveRequest::where('status', 'approved')->where('from_on', '<=', $data['work_on'])->where('to_on', '>=', $data['work_on'])->pluck('employee_id')->flip();
        DB::transaction(function () use ($data, $onLeave, $request) {
            $marked = Attendance::where('work_on', $data['work_on'])->pluck('employee_id')->flip();
            foreach (Employee::where('is_active', true)->get() as $e) {
                if (! isset($marked[$e->id])) {
                    Attendance::create(['employee_id' => $e->id, 'work_on' => $data['work_on'], 'status' => isset($onLeave[$e->id]) ? 'leave' : 'present', 'recorded_by' => $request->user()->name]);
                }
            }
        });

        return back()->with('success', 'Attendance marked.');
    }

    public function requestLeave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'], 'from_on' => ['required', 'date'], 'to_on' => ['required', 'date', 'after_or_equal:from_on'],
            'kind' => ['required', Rule::in(array_keys(LeaveRequest::KINDS))], 'reason' => ['nullable', 'string', 'max:500'],
        ]);
        LeaveRequest::create($data + ['requested_by' => $request->user()->name]);

        return back()->with('success', 'Leave request saved.');
    }

    public function decide(Request $request, LeaveRequest $leave): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['ceo', 'manager'], true), 403);
        abort_unless($leave->status === 'pending', 422, 'This request was already decided.');
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);
        $leave->update(['status' => $data['decision'], 'decided_by' => $request->user()->name, 'decided_at' => now()]);
        Farm::log($request->user(), ucfirst($data['decision'])." leave for {$leave->employee?->name}", '/hr?tab=leave');

        return back()->with('success', 'Leave '.$data['decision'].'.');
    }
}
