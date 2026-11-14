<?php

namespace App\Http\Controllers;

use App\Support\OpsLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-307/D-309: the Operations Log. ?app=feeding shows that app's operations (each app's Reporting menu opens its own);
 * without app, every operation of every app the person can open (the Reports app). Health operations need the right
 * to see health history.
 */
class OperationsLogController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $app = $request->query('app');
        $app = is_string($app) && isset(OpsLog::APPS[$app]) ? $app : null;
        $opens = fn (string $a) => collect(OpsLog::APPS[$a][1])->contains(fn ($m) => $user->canModule($m));
        abort_if($app && ! $opens($app), 403);
        $ops = OpsLog::forApp($app, $user->passes('see_health_history'));
        if (! $app) { // all apps: only operations from apps this person can open
            $ops = array_filter($ops, fn ($o) => collect($o[1])->contains(fn ($a) => $opens($a)));
        }

        return Inertia::render('Reports/Operations', [
            'app' => $app ? OpsLog::APPS[$app][0] : null,
            'rows' => OpsLog::rows($ops),
            'ops' => collect($ops)->map(fn ($o, $k) => ['key' => $k, 'app' => OpsLog::APPS[$app ?? $o[1][0]][0], 'label' => $o[0]])->values(), // D-310: inside an app, all are its own
        ]);
    }
}
