<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Support\HerdStats;
use App\Support\Scorecard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** D-233: Dashboard → CEO Scorecard: the classification framework's KPI groups for the whole herd. */
class ScorecardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $goats = Animal::active()->with('breed')->get();

        return Inertia::render('Scorecard', [
            'groups' => Scorecard::build($goats, HerdStats::tagsFor($goats), $request->user()->canModule('finance')),
            'asOf' => now()->format('d/m/Y g:i A'),
        ]);
    }
}
