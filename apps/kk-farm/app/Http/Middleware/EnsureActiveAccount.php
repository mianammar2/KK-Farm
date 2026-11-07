<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A disabled account is signed out on its next request, and an account with a temporary password
 * can only change it until it has done so (D-044).
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && ! $user->active) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect('/login')->withErrors(['login' => 'This account has been disabled.']);
        }
        if ($user && $user->must_change_password && ! $request->is('account/password', 'logout')) {
            return redirect('/account/password');
        }

        return $next($request);
    }
}
