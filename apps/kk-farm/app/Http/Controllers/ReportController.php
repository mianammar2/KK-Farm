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

        // D-243: each report only goes to people who may open it (its own page); the master page shows the rest
        $allowed = collect(['herd', 'age', 'breeds', 'sires', 'exits', 'spending'])->filter(fn ($x) => \App\Support\Pages::allows($request->user(), '/reports', ['section' => $x]))->values();
        $props = [
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
                $counts = Animal::where('status', 'active')->get()->countBy(fn ($a) => \App\Support\Teeth::code($a->teethCode())); // D-238: teeth follow age

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
        ];
        $needs = ['breeds' => ['herd', 'breeds'], 'ages' => ['age'], 'teeth' => ['age'], 'exits' => ['exits'], 'breeding' => ['herd'], 'sires' => ['sires'], 'costs' => ['spending']];
        foreach ($needs as $prop => $sections) {
            if (array_key_exists($prop, $props) && $allowed->intersect($sections)->isEmpty()) {
                $props[$prop] = in_array($prop, ['breeding', 'costs'], true) ? null : [];
            }
        }

        // D-266 F-13: a person with no report section of their own (the feeding staff) gets the usage and waste views of their work, with no costs
        $links = $allowed->isNotEmpty() ? collect() : collect(['feeding.feed_offered_refused_wasted', 'feeding.feeding_history'])
            ->filter(fn ($k) => \App\Support\Pages::allowsKey($request->user(), $k))
            ->map(fn ($k) => ['label' => \App\Support\Pages::registry()[$k]['label'], 'href' => \App\Support\Pages::registry()[$k]['path'].'?'.http_build_query(\App\Support\Pages::registry()[$k]['query'] ?? [])])->values();

        return Inertia::render('Reports', $props + ['allowed' => $allowed, 'links' => $links]);
    }
}
