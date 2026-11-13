<?php

use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureModule;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetTenant;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [EnsureActiveAccount::class, SetTenant::class, HandleInertiaRequests::class, \App\Http\Middleware\EnsurePage::class]); // D-241: page access
        // The tenant must be bound before route-model binding resolves {animal} etc. (D-037).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetTenant::class);
        $middleware->alias(['module' => EnsureModule::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function ($response, $e, Request $request) {
            // D-241 (RBAC §5, §13.10): every refused request is written to the activity log for the administrator
            if ($response->getStatusCode() === 403 && ($u = $request->user())) {
                try {
                    \App\Support\Farm::log($u, "Access refused: {$request->method()} {$request->getRequestUri()}", null, 'security');
                } catch (\Throwable) {
                    // never let the audit entry hide the 403
                }
            }
            if (in_array($response->getStatusCode(), [403, 404], true) && ! $request->expectsJson()) {
                return Inertia::render('Error', ['status' => $response->getStatusCode()])
                    ->toResponse($request)->setStatusCode($response->getStatusCode());
            }

            return $response;
        });
    })->create();
