<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\WeightRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-216: Dashboard → Weight Gain & Loss. Every goat on the farm with its latest weight against (a) its previous weighing
 * and (b) its weight at the start of a chosen period, plus its gain since its first weighing: KG and grams a day.
 * Only counted weights are used (held or rejected readings, D-208, are left out and shown as waiting for review).
 */
class WeightChangeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $since = rescue(fn () => Carbon::parse($request->string('since')->toString() ?: now()->subDays(30)->toDateString())->startOfDay(), now()->subDays(30)->startOfDay(), false);
        $goats = Animal::active()->with(['breed', 'pen.shed', 'weights'])->orderBy('farm_number')->get();
        $held = WeightRecord::held()->whereIn('animal_id', $goats->pluck('id'))->selectRaw('animal_id, count(*) as n')->groupBy('animal_id')->pluck('n', 'animal_id');
        $pt = fn ($w) => $w ? ['kg' => (float) $w->weight_kg, 'on' => $w->measured_on->format('j M Y')] : null;
        $diff = function ($a, $b): array {
            if (! $a || ! $b || $a->id === $b->id) {
                return ['kg' => null, 'days' => null, 'adg' => null];
            }
            $days = max(1, (int) $a->measured_on->diffInDays($b->measured_on));
            $kg = round($b->weight_kg - $a->weight_kg, 2);

            return ['kg' => $kg, 'days' => $days, 'adg' => (int) round($kg * 1000 / $days)];
        };

        $rows = $goats->map(function (Animal $a) use ($since, $held, $pt, $diff) {
            $w = $a->weights->sortBy([['measured_on', 'asc'], ['id', 'asc']])->values();
            $last = $w->last();
            $prev = $w->count() > 1 ? $w[$w->count() - 2] : null;
            $base = $w->filter(fn ($x) => $x->measured_on->lte($since))->last() ?? $w->first(); // weight at the start of the period (or the first one after it)

            return ['id' => $a->id, 'number' => $a->farm_number, 'animalId' => $a->animal_code, 'batch' => str_contains($a->farm_number, '-') ? substr($a->farm_number, 0, strrpos($a->farm_number, '-')) : $a->farm_number,
                'breed' => $a->breed?->name, 'sex' => $a->sex, 'pen' => $a->pen?->fullName(), 'count' => $w->count(), 'held' => (int) ($held[$a->id] ?? 0),
                'first' => $pt($w->first()), 'prev' => $pt($prev), 'latest' => $pt($last), 'base' => $pt($base),
                'sincePrev' => $diff($prev, $last), 'period' => $diff($base, $last), 'total' => $diff($w->first(), $last)];
        })->values();
        $withPrev = $rows->filter(fn ($r) => $r['sincePrev']['kg'] !== null);
        $withPeriod = $rows->filter(fn ($r) => $r['period']['kg'] !== null);

        return Inertia::render('WeightChange', [
            'rows' => $rows,
            'since' => $since->toDateString(),
            'kpi' => [
                'weighed' => $rows->where('count', '>', 0)->count(), 'goats' => $rows->count(),
                'gaining' => $withPrev->filter(fn ($r) => $r['sincePrev']['kg'] > 0)->count(), 'losing' => $withPrev->filter(fn ($r) => $r['sincePrev']['kg'] < 0)->count(),
                'avgAdg' => $withPrev->count() ? (int) round($withPrev->avg(fn ($r) => $r['sincePrev']['adg'])) : null,
                'periodAdg' => $withPeriod->count() ? (int) round($withPeriod->avg(fn ($r) => $r['period']['adg'])) : null,
                'held' => (int) $held->sum(),
            ],
        ]);
    }
}
