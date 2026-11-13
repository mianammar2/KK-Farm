<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Server-side permission check. Hiding a menu item is never the control. */
class EnsureModule
{
    public function handle(Request $request, Closure $next, string ...$modules) // D-241: module:a,b = any one of them
    {
        abort_unless(collect($modules)->contains(fn ($m) => $request->user()?->canModule($m)), 403, 'Your role does not have access to this area.');

        return $next($request);
    }
}
