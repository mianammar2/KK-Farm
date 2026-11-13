<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Pen;
use App\Models\Task;
use App\Models\User;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function index(Request $request): Response
    {
        $me = $request->user();
        $today = now()->startOfDay();
        // D-078: every unfinished task (whatever its date) plus what was finished or cancelled in the last 7 days.
        $tasks = Task::with(['employee', 'pen.shed', 'department', 'user', 'animal'])->visibleTo($me) // D-241: own and department tasks only
            ->where(fn ($q) => $q->whereIn('status', Task::OPEN)->orWhere('updated_at', '>=', now()->subDays(7)))
            ->orderByRaw("case priority when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")->orderBy('due_on')->orderBy('id')->get();

        return Inertia::render('Tasks', [
            'tasks' => $tasks->map(fn (Task $t) => [
                'id' => $t->id, 'title' => $t->title, 'type' => Task::TYPES[$t->type] ?? $t->type, 'priority' => $t->priority, 'status' => $t->status, 'open' => in_array($t->status, Task::OPEN, true),
                'pen' => $t->pen?->fullName(), 'goatId' => $t->animal_id, 'goat' => $t->animal?->farm_number, 'notes' => $t->notes,
                'assignee' => $t->user?->name ?? $t->employee?->name, 'department' => $t->department?->name,
                'mine' => $t->user_id === $me->id || ($t->user_id === null && $t->department_id !== null && $t->department_id === $me->department_id),
                'due' => $t->due_on->format('j M'), 'dueIso' => $t->due_on->toDateString(),
                'alert' => in_array($t->status, Task::OPEN, true) ? ($t->due_on->lessThan($today) ? 'overdue' : ($t->due_on->lessThanOrEqualTo($today->copy()->addDays(7)) ? 'soon' : null)) : null,
                'completedBy' => $t->completed_by, 'completedAt' => $t->completed_at?->format('j M g:i A'),
            ]),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'users' => User::where('org_id', $me->org_id)->where('active', true)->orderBy('name')->get(['id', 'name', 'role', 'department_id'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->roleLabel(), 'department' => $u->department_id]),
            'pens' => Pen::with('shed')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName()])->sortBy('name')->values(),
            'goats' => \App\Models\Animal::active()->orderBy('farm_number')->get(['id', 'farm_number']),
            'types' => Task::TYPES, 'priorities' => Task::PRIORITIES, 'statuses' => Task::STATUSES,
        ]);
    }

    /** D-052/D-078: a task goes to a department or a user, with a due date, optional goat or pen, type and priority. */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user();
        if (! in_array($me->role, ['ceo', 'manager'], true)) { // D-241 (RBAC §9.3): others may only make tasks for themselves
            $request->merge(['user_id' => $me->id, 'department_id' => $me->department_id ?? \App\Models\Department::forRole($me->role)]);
        }
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(Task::TYPES))],
            'department_id' => ['nullable', 'required_without:user_id', 'exists:departments,id'],
            'user_id' => ['nullable', 'required_without:department_id', Rule::exists('users', 'id')->where('org_id', $request->user()->org_id)->where('active', true)],
            'pen_id' => ['nullable', 'exists:pens,id'], 'animal_id' => ['nullable', 'exists:animals,id'],
            'priority' => ['required', Rule::in(array_keys(Task::PRIORITIES))],
            'due_on' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:500'],
        ], ['department_id.required_without' => 'Choose a department or a person.', 'user_id.required_without' => 'Choose a department or a person.']);

        if (! empty($data['user_id']) && empty($data['department_id'])) {
            $data['department_id'] = User::find($data['user_id'])->department_id;
        }
        $data['due_on'] ??= now()->toDateString();
        Task::create($data + ['status' => 'open']);
        Farm::log($request->user(), "Assigned the task: {$data['title']}", '/tasks');

        return back()->with('success', 'Task assigned.');
    }

    /** Tick on/off: completed ↔ open. */
    public function toggle(Request $request, Task $task): RedirectResponse
    {
        return $this->setStatus($request, $task, $task->status === 'completed' ? 'open' : 'completed');
    }

    public function status(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Task::STATUSES))]]);

        return $this->setStatus($request, $task, $data['status']);
    }

    private function setStatus(Request $request, Task $task, string $status): RedirectResponse
    {
        abort_unless(Task::whereKey($task->id)->visibleTo($request->user())->exists(), 403); // D-241: only own and department tasks
        $closed = in_array($status, ['completed', 'cancelled'], true);
        $task->update(['status' => $status, 'completed_at' => $closed ? now() : null, 'completed_by' => $closed ? $request->user()->name : null]);
        Farm::log($request->user(), Task::STATUSES[$status].": {$task->title}", '/tasks');

        return back();
    }
}
