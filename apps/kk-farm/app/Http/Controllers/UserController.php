<?php

namespace App\Http\Controllers;

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
                'id' => $u->id, 'name' => $u->name, 'login' => $u->email, 'role' => $u->role, 'active' => $u->active,
                'mustChange' => $u->must_change_password, 'lastLogin' => $u->last_login_at?->format('j M Y, H:i'), 'me' => $u->is($request->user()),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'login' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._@+-]+$/', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(array_keys(config('kkfarm.roles')))],
            'password' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $user = User::create([
            'name' => $data['name'], 'email' => strtolower($data['login']), 'role' => $data['role'], 'org_id' => $request->user()->org_id,
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
        ]);

        if ($user->is($request->user()) && ((isset($data['active']) && ! $data['active']) || (isset($data['role']) && $data['role'] !== 'ceo'))) {
            throw ValidationException::withMessages(['role' => 'You cannot disable your own account or remove your own owner role.']);
        }

        $changes = array_intersect_key($data, array_flip(['role', 'active']));
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
