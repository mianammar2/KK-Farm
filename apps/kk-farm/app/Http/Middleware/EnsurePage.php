<?php

namespace App\Http\Middleware;

use App\Support\Pages;
use Closure;
use Illuminate\Http\Request;

/** D-241: server-side page access (RBAC §2.2, §8). A page the role may not open answers 403 and is logged. */
class EnsurePage
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || ! $request->isMethod('GET')) {
            return $next($request);
        }
        $path = '/'.trim($request->path(), '/');
        if ($path === '/' && ! Pages::allows($user, '/')) {
            return redirect(Pages::home($user)); // the Executive Dashboard is for the owner and manager; others have their own home
        }
        if (! Pages::allows($user, $path, $request->query())) {
            abort(403, "You don't have access to this page.");
        }

        return $next($request);
    }
}
