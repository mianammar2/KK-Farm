<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Task;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(): Response
    {
        $today = now()->toDateString();

        return Inertia::render('Employees', [
            'employees' => Employee::orderBy('code')->get()->map(fn (Employee $e) => [
                'id' => $e->id, 'code' => $e->code, 'name' => $e->name, 'role' => $e->role, 'shift' => $e->shift, 'active' => $e->is_active,
                'open' => Task::where('employee_id', $e->id)->where('due_on', $today)->whereIn('status', Task::OPEN)->count(),
                'done' => Task::where('employee_id', $e->id)->where('due_on', $today)->where('status', 'completed')->count(),
            ]),
            'roles' => array_values(config('kkfarm.roles')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_staff'), 403, 'Only the Administrator and the Farm Manager add staff.'); // D-266 F-03
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'role' => ['required', 'string', 'max:40'], 'shift' => ['required', 'string', 'max:20']]);

        $next = Employee::where('code', 'like', 'EMP-%')->count() + 1;
        $employee = Employee::create($data + ['code' => sprintf('EMP-%02d', $next)]);
        Farm::log($request->user(), "Added employee {$employee->code}", '/employees');

        return back()->with('success', "{$employee->code} added.");
    }
}
