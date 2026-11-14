<?php

namespace App\Http\Middleware;

use App\Support\Pages;
use Closure;
use Illuminate\Http\Request;

/**
 * D-266 (audit F-01): every write route (POST/PUT/PATCH/DELETE) declares its rule in config/kkfarm_actions.php and the
 * server enforces it here, before the request is bound to a record or reaches a controller. Deny by default: a signed-in
 * write request whose route has no row, or whose row the person does not satisfy, answers 403 and is logged.
 */
class EnforceAction
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $route = $request->route();
        if (! $user || ! $route || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        $row = config('kkfarm_actions')[$request->method().' /'.ltrim($route->uri(), '/')] ?? null;
        abort_unless($row !== null && self::allows($user, $row), 403, 'You do not have the right to do this.');

        return $next($request);
    }

    /** May this person do what the row describes? */
    public static function allows(\App\Models\User $user, array $row): bool
    {
        if (isset($row['open'])) {
            return true;
        }
        $ok = fn (array|string $any, callable $test) => collect((array) $any)->contains(fn ($x) => $test($x));
        if (isset($row['module']) && ! $ok($row['module'], fn ($m) => $user->canModule($m))) {
            return false;
        }
        if (isset($row['gate']) && ! $ok($row['gate'], fn ($g) => $user->passes($g))) {
            return false;
        }
        if (isset($row['page']) && ! Pages::allowsKey($user, $row['page'])) {
            return false;
        }

        return isset($row['module']) || isset($row['gate']) || isset($row['page']);
    }
}
