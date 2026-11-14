<?php

namespace App\Http\Controllers;

use App\Support\AppDashboards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** D-257 / D-258: an app's key numbers and what needs attention, shown under the title of the app's main page. */
class AppDashboardController extends Controller
{
    public function __invoke(Request $request, string $app): JsonResponse
    {
        abort_unless(isset(AppDashboards::APPS[$app]), 404);
        abort_unless(AppDashboards::opens($request->user(), $app), 403, 'Your role does not open this app.');
        $d = AppDashboards::build($request->user(), $app);

        return response()->json([
            'kpis' => collect($d['kpis'])->map(fn ($k) => ['label' => $k['label'], 'value' => $k['value'], 'url' => $k['url'], 'tone' => $k['tone']])->values(),
            'alerts' => collect($d['alerts'])->map(fn ($a) => ['key' => $a['key'], 'label' => $a['label'], 'count' => $a['count'], 'severity' => $a['severity'], 'url' => $a['url']])->values(),
        ]);
    }
}
