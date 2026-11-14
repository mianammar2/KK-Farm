<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Support\Access;
use App\Support\Farm;
use App\Support\Pages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner manages who can sign in (D-044): add an account with a temporary password, change its role,
 * disable it, or reset its password. Users are global identities, so every query is scoped to the owner's
 * organization explicitly (the users table has no row-level security: sign-in must find the user first).
 *
 * D-244: one list with search, filters and a summary; a drawer per person (profile, access, security, activity);
 * bulk department and sign-in changes; departments as their own tab. Administrator only, page and API. Every
 * change goes to the Activity Log (security entries, owner only) with old → new. No password, hash or token is
 * ever sent to the browser, except a newly generated temporary password, shown once.
 */
class UserController extends Controller
{
    private const STATUSES = ['active', 'disabled', 'never', 'custom'];

    private function owner(Request $request): void
    {
        abort_unless($request->user()->role === 'ceo', 403, 'Only the Administrator manages users.');
    }

    private function scope(Request $request)
    {
        return User::where('org_id', $request->user()->org_id);
    }

    /** How many own ticks (apps, actions, pages) a person has on top of their role. */
    private function own(User $u): int
    {
        return count($u->module_access ?? []) + count($u->gate_access ?? []) + count(Pages::userTicks($u));
    }

    private function row(User $u, Request $request): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'login' => $u->email, 'email' => $u->contact_email, 'role' => $u->role, 'active' => (bool) $u->active,
            'department' => $u->department_id, 'mustChange' => (bool) $u->must_change_password,
            'lastLogin' => $u->last_login_at?->toIso8601String(), 'me' => $u->is($request->user()), 'own' => $this->own($u),
            'diffs' => collect(array_keys(config('kkfarm.roles')))->reject(fn ($r) => $r === $u->role)->mapWithKeys(fn ($r) => [$r => Access::roleDiff($u, $r)]), // D-241
        ];
    }

    public function index(Request $request): Response
    {
        $this->owner($request);
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:80'], 'role' => ['nullable', Rule::in(array_keys(config('kkfarm.roles')))],
            'dept' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(self::STATUSES)], 'sort' => ['nullable', Rule::in(['name', 'last', 'role'])],
        ]);
        $all = $this->scope($request)->orderBy('name')->get();
        $custom = fn (User $u) => $this->own($u) > 0;
        $q = Str::lower(trim($f['q'] ?? ''));
        $users = $all->filter(fn (User $u) => ($q === '' || Str::contains(Str::lower("{$u->name} {$u->email} {$u->contact_email}"), $q))
            && (empty($f['role']) || $u->role === $f['role'])
            && (empty($f['dept']) || (int) $u->department_id === (int) $f['dept'])
            && match ($f['status'] ?? null) {
                'active' => (bool) $u->active, 'disabled' => ! $u->active, 'never' => $u->last_login_at === null, 'custom' => $custom($u), default => true,
            });
        $roleOrder = array_flip(array_keys(config('kkfarm.roles')));
        $users = match ($f['sort'] ?? 'name') {
            'last' => $users->sortByDesc(fn ($u) => $u->last_login_at?->timestamp ?? 0),
            'role' => $users->sortBy(fn ($u) => sprintf('%02d %s', $roleOrder[$u->role] ?? 99, Str::lower($u->name))),
            default => $users->sortBy(fn ($u) => Str::lower($u->name)),
        };
        $openTasks = Task::whereIn('status', Task::OPEN)->whereNotNull('department_id')->selectRaw('department_id, count(*) as n')->groupBy('department_id')->pluck('n', 'department_id');
        $roleModules = Access::roleModules($request->user()->org_id);

        return Inertia::render('Settings/Users', [
            'users' => $users->map(fn (User $u) => $this->row($u, $request))->values(),
            'summary' => [
                'total' => $all->count(), 'active' => $all->where('active', true)->count(), 'disabled' => $all->where('active', false)->count(),
                'never' => $all->whereNull('last_login_at')->count(), 'custom' => $all->filter($custom)->count(),
                'activeAdmins' => $all->where('role', 'ceo')->where('active', true)->count(),
            ],
            'filters' => ['q' => $f['q'] ?? '', 'role' => $f['role'] ?? '', 'dept' => isset($f['dept']) ? (string) $f['dept'] : '', 'status' => $f['status'] ?? '', 'sort' => $f['sort'] ?? 'name'],
            'departments' => Department::orderBy('name')->get()->map(fn (Department $d) => [
                'id' => $d->id, 'name' => $d->name,
                'members' => $all->where('department_id', $d->id)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'active' => (bool) $u->active])->values(),
                'openTasks' => (int) ($openTasks[$d->id] ?? 0),
            ]),
            // what each role opens by default (shown when adding a person or changing a role) and where it lands
            'roleApps' => collect(array_keys(config('kkfarm.roles')))->mapWithKeys(fn ($r) => [$r => collect($roleModules)->filter(fn ($roles) => in_array($r, $roles, true))->keys()->map(fn ($m) => Access::MODULE_LABELS[$m] ?? $m)->values()]),
            'roleHome' => collect(array_keys(config('kkfarm.roles')))->mapWithKeys(fn ($r) => [$r => in_array($r, ['ceo', 'manager'], true) ? 'Executive Dashboard' : 'My Home']),
            'tempPassword' => fn () => $request->session()->get('temp_password'), // shown once, right after a reset
        ]);
    }

    /** D-244: the drawer's Access and Activity tabs, loaded when a person is opened. */
    public function details(Request $request, int $id): JsonResponse
    {
        $this->owner($request);
        $u = $this->scope($request)->findOrFail($id);
        $pages = collect(Pages::registry())->reject(fn ($r) => in_array($r['lock'], ['opening', 'always'], true));
        $open = $pages->filter(fn ($r) => Pages::allowsKey($u, $r['key']));
        $roleMods = Access::roleModules($u->org_id);
        $roleGates = Access::roleGates($u->org_id);
        $custom = collect()
            ->merge(collect($u->module_access ?? [])->map(fn ($v, $m) => ['kind' => 'App', 'label' => Access::MODULE_LABELS[$m] ?? $m, 'given' => (bool) $v]))
            ->merge(collect($u->gate_access ?? [])->map(fn ($v, $g) => ['kind' => 'Action', 'label' => Access::labels()[$g] ?? $g, 'given' => (bool) $v]))
            ->merge(collect(Pages::userTicks($u))->map(fn ($v, $k) => ['kind' => 'Page', 'label' => Pages::registry()[$k]['label'] ?? $k, 'given' => (bool) $v]))
            ->values();

        return response()->json([
            'apps' => collect(Access::MODULE_LABELS)->filter(fn ($label, $m) => $u->canModule($m))->values(),
            'actions' => collect(Access::labels())->filter(fn ($label, $g) => $u->passes($g))->values(),
            'pagesOpen' => $open->count(), 'pagesTotal' => $pages->count(),
            'custom' => $custom,
            'roleApps' => collect($roleMods)->filter(fn ($roles) => in_array($u->role, $roles, true))->count(),
            'roleActions' => collect($roleGates)->filter(fn ($roles) => in_array($u->role, $roles, true))->count(),
            'activity' => Activity::where('actor', $u->name)->latest('id')->limit(20)->get()->map(fn ($a) => ['id' => $a->id, 'action' => $a->action, 'url' => $a->url, 'at' => $a->created_at?->toIso8601String()]),
        ]);
    }

    /** D-244: is this username free? (instant feedback while typing) */
    public function checkLogin(Request $request): JsonResponse
    {
        $this->owner($request);
        $login = strtolower(trim((string) $request->query('login', '')));
        $except = (int) $request->query('except', 0);

        return response()->json([
            'valid' => $login !== '' && preg_match('/^[A-Za-z0-9._@+-]+$/', $login) === 1,
            'free' => $login !== '' && User::whereRaw('lower(email) = ?', [$login])->when($except, fn ($q) => $q->whereKeyNot($except))->doesntExist(),
        ]);
    }

    /** D-052: the owner adds the farm's own departments (or renames one). */
    public function storeDepartment(Request $request): RedirectResponse
    {
        $this->owner($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:60', 'unique:departments,name']]);
        Department::create($data);
        Farm::log($request->user(), "Added department {$data['name']}", '/settings/users?tab=departments', 'security');

        return back()->with('success', "{$data['name']} department added.");
    }

    public function updateDepartment(Request $request, Department $department): RedirectResponse
    {
        $this->owner($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:60', Rule::unique('departments', 'name')->ignore($department->id)]]);
        $old = $department->name;
        $department->update($data);
        Farm::log($request->user(), "Renamed department {$old} → {$data['name']}", '/settings/users?tab=departments', 'security');

        return back()->with('success', 'Department renamed.');
    }

    /** D-244: a department is deleted only when nobody is in it and it has no open tasks. */
    public function destroyDepartment(Request $request, Department $department): RedirectResponse
    {
        $this->owner($request);
        $people = $this->scope($request)->where('department_id', $department->id)->count();
        $tasks = Task::where('department_id', $department->id)->whereIn('status', Task::OPEN)->count();
        if ($people || $tasks) {
            throw ValidationException::withMessages(['department' => "{$department->name} cannot be deleted: ".collect([
                $people ? "$people ".Str::plural('person', $people).' still in it' : null,
                $tasks ? "$tasks open ".Str::plural('task', $tasks) : null,
            ])->filter()->implode(' and ').'. Move them first.']);
        }
        Task::where('department_id', $department->id)->update(['department_id' => null]); // finished tasks keep their history without the name
        $name = $department->name;
        $department->delete();
        Farm::log($request->user(), "Deleted department {$name}", '/settings/users?tab=departments', 'security');

        return back()->with('success', "{$name} department deleted.");
    }

    public function store(Request $request): RedirectResponse
    {
        $this->owner($request);
        $request->merge(['login' => strtolower(trim((string) $request->input('login')))]); // usernames are unique whatever the case
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'login' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._@+-]+$/', Rule::unique('users', 'email')],
            'email' => ['nullable', 'email', 'max:160'],
            'role' => ['required', Rule::in(array_keys(config('kkfarm.roles')))],
            'password' => ['required', 'string', 'min:8', 'max:200'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ], ['login.unique' => 'This username is already taken.']);

        $user = User::create([
            'name' => $data['name'], 'email' => strtolower($data['login']), 'contact_email' => $data['email'] ?? null, 'role' => $data['role'], 'org_id' => $request->user()->org_id,
            'department_id' => $data['department_id'] ?? Department::forRole($data['role']),
            'password' => Hash::make($data['password']), 'must_change_password' => true, 'active' => true,
        ]);
        Farm::log($request->user(), "Added user {$user->name} ({$user->roleLabel()})", '/settings/users', 'security');

        return back()->with('success', "{$user->name} can now sign in as {$user->email}. They will choose their own password on first sign-in.");
    }

    /** The guards that hold whatever the screen sends: never yourself, never the last active Administrator. */
    private function guard(Request $request, User $user, ?string $role, ?bool $active): void
    {
        $me = $user->is($request->user());
        if ($me && (($active === false) || ($role !== null && $role !== 'ceo'))) {
            throw ValidationException::withMessages(['role' => 'You cannot disable your own account or remove your own Administrator role.']);
        }
        $demotes = $user->role === 'ceo' && (($role !== null && $role !== 'ceo') || $active === false);
        if ($demotes && $this->scope($request)->where('role', 'ceo')->where('active', true)->whereKeyNot($user->id)->doesntExist()) {
            throw ValidationException::withMessages(['role' => 'At least one active Administrator must remain.']);
        }
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $this->owner($request);
        $user = $this->scope($request)->findOrFail($id);
        if ($request->has('login')) {
            $request->merge(['login' => strtolower(trim((string) $request->input('login')))]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'login' => ['sometimes', 'string', 'max:120', 'regex:/^[A-Za-z0-9._@+-]+$/', Rule::unique('users', 'email')->ignore($user->id)],
            'email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'role' => ['sometimes', Rule::in(array_keys(config('kkfarm.roles')))],
            'active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:200'],
            'department_id' => ['sometimes', 'nullable', 'exists:departments,id'],
        ], ['login.unique' => 'This username is already taken.']);
        $this->guard($request, $user, $data['role'] ?? null, array_key_exists('active', $data) ? (bool) $data['active'] : null);

        if (isset($data['role']) && $data['role'] !== $user->role) {
            $diff = Access::roleDiff($user, $data['role']);
            Farm::log($request->user(), "Changed the role of {$user->name}: {$user->roleLabel()} → ".config("kkfarm.roles.{$data['role']}").($diff['gain'] ? '; gains '.implode(', ', $diff['gain']) : '').($diff['lose'] ? '; loses '.implode(', ', $diff['lose']) : ''), '/settings/users', 'security');
        }
        $changes = [];
        foreach (['name' => 'name', 'login' => 'email', 'email' => 'contact_email', 'active' => 'active', 'department_id' => 'department_id'] as $in => $col) {
            if (array_key_exists($in, $data)) {
                $changes[$col] = $in === 'login' ? strtolower($data[$in]) : $data[$in];
            }
        }
        if (isset($data['role'])) {
            $changes['role'] = $data['role'];
        }
        $label = ['name' => 'name', 'email' => 'username', 'contact_email' => 'email', 'active' => 'sign-in', 'department_id' => 'department'];
        $show = fn ($col, $v) => match ($col) {
            'active' => $v ? 'allowed' : 'blocked', 'department_id' => $v ? (Department::find($v)?->name ?? '—') : 'none', default => $v ?? '—',
        };
        foreach ($label as $col => $what) {
            if (array_key_exists($col, $changes) && (string) $changes[$col] !== (string) $user->{$col}) {
                Farm::log($request->user(), "Changed the {$what} of {$user->name}: ".$show($col, $user->{$col}).' → '.$show($col, $changes[$col]), '/settings/users', 'security');
            }
        }
        if (! empty($data['password'])) {
            $changes += ['password' => Hash::make($data['password']), 'must_change_password' => true];
            Farm::log($request->user(), "Set a temporary password for {$user->name}", '/settings/users', 'security');
        }
        $user->forceFill($changes)->save();
        if (($changes['active'] ?? true) === false) {
            $this->signOutEverywhere($user); // disabled: signed out at once
        }

        return back()->with('success', ! empty($data['password']) ? "Temporary password set for {$user->name}." : "{$user->name} saved.");
    }

    /** D-244: a new temporary password, generated here, shown once; the person must change it at the next sign-in. */
    public function resetPassword(Request $request, int $id): RedirectResponse
    {
        $this->owner($request);
        $user = $this->scope($request)->findOrFail($id);
        $temp = Str::lower(Str::random(4)).'-'.random_int(1000, 9999).'-'.Str::lower(Str::random(4));
        $user->forceFill(['password' => Hash::make($temp), 'must_change_password' => true])->save();
        $this->signOutEverywhere($user);
        Farm::log($request->user(), "Reset the password of {$user->name} (temporary password, must change at next sign-in)", '/settings/users', 'security');

        return back()->with('temp_password', ['id' => $user->id, 'name' => $user->name, 'login' => $user->email, 'password' => $temp]);
    }

    public function signOut(Request $request, int $id): RedirectResponse
    {
        $this->owner($request);
        $user = $this->scope($request)->findOrFail($id);
        abort_if($user->is($request->user()), 422, 'Use Sign out in your own menu.');
        $this->signOutEverywhere($user);
        Farm::log($request->user(), "Signed {$user->name} out everywhere", '/settings/users', 'security');

        return back()->with('success', "{$user->name} is signed out on every device.");
    }

    private function signOutEverywhere(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    /** D-244: several people at once: department, or sign-in on/off. Role changes stay one person at a time (they need the preview). */
    public function bulk(Request $request): RedirectResponse
    {
        $this->owner($request);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['department', 'enable', 'disable'])],
            'department_id' => ['nullable', 'required_if:action,department', 'exists:departments,id'],
        ]);
        $done = 0;
        $skipped = [];
        foreach ($this->scope($request)->whereIn('id', $data['ids'])->get() as $u) {
            try {
                $active = $data['action'] === 'department' ? null : $data['action'] === 'enable';
                $this->guard($request, $u, null, $active);
            } catch (ValidationException $e) {
                $skipped[] = "{$u->name} (".collect($e->errors())->flatten()->first().')';

                continue;
            }
            if ($data['action'] === 'department') {
                if ((int) $u->department_id !== (int) $data['department_id']) {
                    Farm::log($request->user(), "Changed the department of {$u->name}: ".($u->department?->name ?? 'none').' → '.Department::find($data['department_id'])->name, '/settings/users', 'security');
                    $u->forceFill(['department_id' => $data['department_id']])->save();
                }
            } elseif ((bool) $u->active !== $active) {
                Farm::log($request->user(), "Changed the sign-in of {$u->name}: ".($u->active ? 'allowed' : 'blocked').' → '.($active ? 'allowed' : 'blocked'), '/settings/users', 'security');
                $u->forceFill(['active' => $active])->save();
                if (! $active) {
                    $this->signOutEverywhere($u);
                }
            }
            $done++;
        }
        if ($skipped) {
            throw ValidationException::withMessages(['bulk' => "Done for $done; not changed: ".implode('; ', $skipped)]);
        }

        return back()->with('success', "$done ".Str::plural('person', $done).' updated.');
    }
}
