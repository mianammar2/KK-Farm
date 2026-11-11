<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => $user ? [
                'name' => $user->name,
                'role' => $user->role,
                'roleLabel' => $user->roleLabel(),
                'modules' => array_values(array_filter(array_keys(config('kkfarm.modules')), fn ($m) => $user->canModule($m))),
                'gates' => array_values(array_filter(array_keys(config('kkfarm.gates')), fn ($g) => $user->passes($g))), // D-215
            ] : null,
            'roles' => config('kkfarm.roles'),
            'demo' => (bool) config('kkfarm.demo'),
            'demoLogin' => \App\Http\Controllers\AuthController::demoLogin(),
            'flash' => fn () => $request->session()->get('success'),
        ];
    }
}
