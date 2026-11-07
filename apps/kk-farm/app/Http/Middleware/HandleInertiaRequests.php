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
                'modules' => array_keys(array_filter(config('kkfarm.modules'), fn ($roles) => in_array($user->role, $roles, true))),
                'gates' => array_keys(array_filter(config('kkfarm.gates'), fn ($roles) => in_array($user->role, $roles, true))),
            ] : null,
            'roles' => config('kkfarm.roles'),
            'demo' => (bool) config('kkfarm.demo'),
            'demoLogin' => \App\Http\Controllers\AuthController::demoLogin(),
            'flash' => fn () => $request->session()->get('success'),
        ];
    }
}
