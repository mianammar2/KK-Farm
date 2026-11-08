<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Kidding;
use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $breedRows = DB::table('animals')->join('breeds', 'breeds.id', '=', 'animals.breed_id')->where('status', 'active')
            ->selectRaw("breeds.name, count(*) as total, count(*) filter (where sex = 'female') as female, count(*) filter (where sex = 'male') as male, count(*) filter (where health_status <> 'healthy') as unwell")
            ->groupBy('breeds.name')->orderByDesc('total')->get();

        return Inertia::render('Reports', [
            'breeds' => $breedRows,
            'ages' => DB::table('animals')->where('status', 'active')
                ->selectRaw("case when birth_date is null then 'Unknown' when birth_date > now() - interval '6 months' then 'Under 6 mo' when birth_date > now() - interval '18 months' then '6 to 18 mo' when birth_date > now() - interval '48 months' then '18 mo to 4 yr' else 'Over 4 yr' end as band, count(*) as value")
                ->groupBy('band')->orderByRaw('min(birth_date) desc nulls last')->get(),
            'exits' => Animal::where('status', '!=', 'active')->selectRaw('exit_reason as name, count(*) as value')->groupBy('exit_reason')->get(),
            'breeding' => [
                'kiddings' => Kidding::count(), 'alive' => (int) Kidding::sum('born_alive'), 'stillborn' => (int) Kidding::sum('stillborn'),
                'perKidding' => round((float) Kidding::avg('born_alive'), 2),
            ],
            'sires' => DB::table('animals as kid')->join('animals as sire', 'sire.id', '=', 'kid.sire_id')->groupBy('sire.id', 'sire.farm_number', 'sire.name')
                ->selectRaw('sire.id, sire.farm_number as number, sire.name, count(*) as offspring')->orderByDesc('offspring')->limit(6)->get(),
            'costs' => $request->user()->canModule('finance') ? LedgerEntry::where('direction', 'out')->where('occurred_on', '>=', now()->subMonths(11)->startOfMonth())->groupBy('category')
                ->select('category as name', DB::raw('sum(amount)::float as value'))->orderByDesc('value')->get() : null,
        ]);
    }
}
