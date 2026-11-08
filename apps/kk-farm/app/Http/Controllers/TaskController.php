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

        return Inertia::render('Tasks', [
            'tasks' => Task::with(['employee', 'pen.shed', 'department', 'user'])->where('due_on', '>=', now()->subDay()->toDateString())->orderByRaw("case priority when 'high' then 0 else 1 end")->orderBy('id')->get()->map(fn (Task $t) => [
                'id' => $t->id, 'title' => $t->title, 'type' => $t->type, 'priority' => $t->priority, 'status' => $t->status, 'pen' => $t->pen?->fullName(),
                'assignee' => $t->user?->name ?? $t->employee?->name, 'department' => $t->department?->name,
                'mine' => $t->user_id === $me->id || ($t->user_id === null && $t->department_id !== null && $t->department_id === $me->department_id),
                'due' => $t->due_on->format('j M'), 'completedBy' => $t->completed_by, 'completedAt' => $t->completed_at?->format('g:i A'),
            ]),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'users' => User::where('org_id', $me->org_id)->where('active', true)->orderBy('name')->get(['id', 'name', 'role', 'department_id'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->roleLabel(), 'department' => $u->department_id]),
            'pens' => Pen::with('shed')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName()])->sortBy('name')->values(),
        ]);
    }

    /** D-052: a task goes to a whole department (anyone in it can do it) or to one user of this farm. */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['ceo', 'manager', 'vet'], true), 403, 'Your role cannot assign tasks.');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['feeding', 'cleaning', 'observation', 'weighing', 'other'])],
            'department_id' => ['nullable', 'required_without:user_id', 'exists:departments,id'],
            'user_id' => ['nullable', 'required_without:department_id', Rule::exists('users', 'id')->where('org_id', $request->user()->org_id)->where('active', true)],
            'pen_id' => ['nullable', 'exists:pens,id'],
            'priority' => ['required', Rule::in(['normal', 'high'])],
        ], ['department_id.required_without' => 'Choose a department or a person.', 'user_id.required_without' => 'Choose a department or a person.']);

        if (! empty($data['user_id']) && empty($data['department_id'])) {
            $data['department_id'] = User::find($data['user_id'])->department_id;
        }
        Task::create($data + ['due_on' => now()->toDateString()]);
        Farm::log($request->user(), "Assigned the task: {$data['title']}", '/tasks');

        return back()->with('success', 'Task assigned.');
    }

    public function toggle(Request $request, Task $task): RedirectResponse
    {
        $done = $task->status !== 'done';
        $task->update(['status' => $done ? 'done' : 'open', 'completed_at' => $done ? now() : null, 'completed_by' => $done ? $request->user()->name : null]);
        if ($done) {
            Farm::log($request->user(), "Completed: {$task->title}", '/tasks');
        }

        return back();
    }
}
