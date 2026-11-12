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
            // D-227: by the farm's age stages (Settings → Age Groups), in stage order; goats with no birth date last.
            'ages' => (function () {
                $stages = \App\Support\AgeStages::stages();
                $counts = Animal::where('status', 'active')->get(['birth_date'])->countBy(fn ($a) => \App\Support\AgeStages::codeFor($a->birth_date) ?? 'none');

                return $stages->map(fn ($st) => ['band' => $st->code, 'value' => $counts[$st->code] ?? 0])->filter(fn ($r) => $r['value'] > 0)
                    ->push(['band' => 'No age', 'value' => $counts['none'] ?? 0])->filter(fn ($r) => $r['value'] > 0)->values();
            })(),
            // D-229: by teeth (dental code from the latest examination); UNK = never examined.
            'teeth' => (function () {
                $counts = Animal::where('status', 'active')->pluck('teeth')->countBy(fn ($t) => \App\Support\Teeth::code($t));

                return \App\Support\Teeth::stages()->map(fn ($t) => ['band' => $t->key, 'value' => $counts[$t->key] ?? 0])->push(['band' => 'UNK', 'value' => $counts['UNK'] ?? 0])->filter(fn ($r) => $r['value'] > 0)->values();
            })(),
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
