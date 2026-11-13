<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner manages who can sign in (D-044): add an account with a temporary password, change its role,
 * disable it, or reset its password. Users are global identities, so every query is scoped to the owner's
 * organization explicitly (the users table has no row-level security: sign-in must find the user first).
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Settings/Users', [
            'users' => $this->scope($request)->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'login' => $u->email, 'role' => $u->role, 'active' => $u->active, 'department' => $u->department_id,
                'mustChange' => $u->must_change_password, 'lastLogin' => $u->last_login_at?->format('j M Y, H:i'), 'me' => $u->is($request->user()),
                'own' => count($u->module_access ?? []) + count($u->gate_access ?? []), // D-215
                'diffs' => collect(array_keys(config('kkfarm.roles')))->reject(fn ($r) => $r === $u->role)->mapWithKeys(fn ($r) => [$r => \App\Support\Access::roleDiff($u, $r)]), // D-241: shown before a role change is saved
            ]),
            'departments' => Department::withCount(['users' => fn ($q) => $q->where('active', true)])->orderBy('name')->get()->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name, 'users' => $d->users_count]),
        ]);
    }

    /** D-052: the owner adds the farm's own departments (or renames one). */
    public function storeDepartment(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60', 'unique:departments,name']]);
        Department::create($data);
        Farm::log($request->user(), "Added department {$data['name']}", '/settings/users');

        return back()->with('success', "{$data['name']} department added.");
    }

    public function updateDepartment(Request $request, Department $department): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60', Rule::unique('departments', 'name')->ignore($department->id)]]);
        $department->update($data);
        Farm::log($request->user(), "Renamed department to {$data['name']}", '/settings/users');

        return back()->with('success', 'Department renamed.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'login' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._@+-]+$/', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(array_keys(config('kkfarm.roles')))],
            'password' => ['required', 'string', 'min:8', 'max:200'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        $user = User::create([
            'name' => $data['name'], 'email' => strtolower($data['login']), 'role' => $data['role'], 'org_id' => $request->user()->org_id,
            'department_id' => $data['department_id'] ?? Department::forRole($data['role']),
            'password' => Hash::make($data['password']), 'must_change_password' => true, 'active' => true,
        ]);
        Farm::log($request->user(), "Added user {$user->name} ({$user->roleLabel()})", '/settings/users');

        return back()->with('success', "{$user->name} can now sign in as {$user->email}. They will choose their own password on first sign-in.");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $user = $this->scope($request)->findOrFail($id);
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(array_keys(config('kkfarm.roles')))],
            'active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:200'],
            'department_id' => ['sometimes', 'nullable', 'exists:departments,id'],
        ]);

        if ($user->is($request->user()) && ((isset($data['active']) && ! $data['active']) || (isset($data['role']) && $data['role'] !== 'ceo'))) {
            throw ValidationException::withMessages(['role' => 'You cannot disable your own account or remove your own owner role.']);
        }

        // D-241 (RBAC §11): the last active Administrator can never be demoted or switched off
        $demotes = $user->role === 'ceo' && ((isset($data['role']) && $data['role'] !== 'ceo') || (isset($data['active']) && ! $data['active']));
        if ($demotes && $this->scope($request)->where('role', 'ceo')->where('active', true)->whereKeyNot($user->id)->doesntExist()) {
            throw ValidationException::withMessages(['role' => 'At least one active Administrator must remain.']);
        }
        if (isset($data['role']) && $data['role'] !== $user->role) {
            $diff = \App\Support\Access::roleDiff($user, $data['role']);
            Farm::log($request->user(), "Changed the role of {$user->name} to ".config("kkfarm.roles.{$data['role']}").($diff['gain'] ? '; gains '.implode(', ', $diff['gain']) : '').($diff['lose'] ? '; loses '.implode(', ', $diff['lose']) : ''), '/settings/users', 'security');
        }

        $changes = array_intersect_key($data, array_flip(['role', 'active', 'department_id']));
        if (! empty($data['password'])) {
            $changes += ['password' => Hash::make($data['password']), 'must_change_password' => true];
        }
        $user->forceFill($changes)->save();
        Farm::log($request->user(), "Updated user {$user->name}", '/settings/users');

        return back()->with('success', ! empty($data['password']) ? "Temporary password set for {$user->name}." : 'User saved.');
    }

    private function scope(Request $request)
    {
        return User::where('org_id', $request->user()->org_id);
    }
}
