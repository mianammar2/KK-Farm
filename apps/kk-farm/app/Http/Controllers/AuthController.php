<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-044: sign-in with a username (or email) and password. The demo role picker exists only when
 * KKFARM_DEMO_LOGIN=true in a local or testing environment, never on a server.
 */
class AuthController extends Controller
{
    public static function demoLogin(): bool
    {
        return (bool) config('kkfarm.demo_login') && app()->environment('local', 'testing');
    }

    public function show(): Response
    {
        return Inertia::render('Login', ['demoLogin' => self::demoLogin()]);
    }

    public function login(Request $request): RedirectResponse
    {
        if ($request->has('role')) {
            return $this->demo($request);
        }

        $data = $request->validate(['login' => ['required', 'string', 'max:120'], 'password' => ['required', 'string', 'max:200'], 'remember' => ['boolean']]);
        $key = 'login:'.Str::lower($data['login']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['login' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $user = User::whereRaw('lower(email) = ?', [Str::lower(trim($data['login']))])->first();
        if (! $user || ! $user->active || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['login' => 'The username or password is not correct.']);
        }

        RateLimiter::clear($key);
        Auth::login($user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return $user->must_change_password ? redirect('/account/password') : redirect()->intended(\App\Support\Pages::home($user)); // D-241: each role's home (RBAC §6)
    }

    private function demo(Request $request): RedirectResponse
    {
        abort_unless(self::demoLogin(), 404);
        $data = $request->validate(['role' => ['required', Rule::in(array_keys(config('kkfarm.roles')))]]);

        // Switching roles stays inside the current organization (D-037).
        Auth::login(User::where('role', $data['role'])->where('active', true)
            ->when(Auth::user(), fn ($query, $current) => $query->where('org_id', $current->org_id))
            ->firstOrFail());
        $request->session()->regenerate();

        return redirect(\App\Support\Pages::home(Auth::user())); // D-241: each role's home (RBAC §6)
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function editPassword(Request $request): Response
    {
        return Inertia::render('Account/Password', ['mustChange' => (bool) $request->user()->must_change_password]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'max:200', 'confirmed', 'different:current_password'],
        ]);

        $request->user()->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => false])->save();
        $request->session()->regenerate();

        return redirect(\App\Support\Pages::home($request->user()))->with('success', 'Password changed.');
    }
}
