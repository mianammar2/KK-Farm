<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;

/**
 * Binds the signed-in user's organization to the database session for the whole request.
 *
 * It must run before route-model binding (SubstituteBindings), otherwise `/goats/{animal}` would be resolved
 * before the tenant is known. See bootstrap/app.php. Guests get a role but no organization, so they see no tenant data.
 */
class SetTenant
{
    public function handle(Request $request, Closure $next)
    {
        Tenant::enter($request->user()?->org_id);

        return $next($request);
    }

    public function terminate(): void
    {
        Tenant::leave();
    }
}
