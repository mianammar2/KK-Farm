<?php

namespace App\Http\Controllers;

use App\Support\AppDashboards;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** D-257: each app's own dashboard, the first thing seen when the app opens. */
class AppDashboardController extends Controller
{
    public function __invoke(Request $request, string $app): Response
    {
        abort_unless(isset(AppDashboards::APPS[$app]), 404);
        abort_unless(AppDashboards::opens($request->user(), $app), 403, 'Your role does not open this app.');

        return Inertia::render('AppDashboard', ['app' => $app] + AppDashboards::build($request->user(), $app));
    }
}
