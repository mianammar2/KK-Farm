<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Simulated sign-in for the local prototype. It is refused outside local and testing environments. */
class AuthController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Login');
    }

    public function login(Request $request): RedirectResponse
    {
        abort_unless(app()->environment('local', 'testing'), 404);
        $data = $request->validate(['role' => ['required', Rule::in(array_keys(config('kkfarm.roles')))]]);

        // Switching roles stays inside the current organization (D-037).
        Auth::login(User::where('role', $data['role'])
            ->when(Auth::user(), fn ($query, $current) => $query->where('org_id', $current->org_id))
            ->firstOrFail());
        $request->session()->regenerate();

        return redirect('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
