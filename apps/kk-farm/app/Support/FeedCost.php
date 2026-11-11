<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\AnimalMovement;
use App\Models\FeedItem;
use App\Models\FeedStockCount;
use App\Models\FeedUsage;
use App\Models\HealthCase;
use App\Models\WeightRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * D-192: V3 journey C Feed-to-True-Cost. Feed issued to a pen (each meal's offered KG at the feed's cost when it was
 * given) is distributed to the goats in that pen at that meal, equally per head; refused and wasted feed stays in the
 * cost (it was bought and issued). A goat's gain is its weight at the end of the period minus its weight at the start
 * (the latest weight on or before each date). Cost per KG gained = feed cost ÷ gain; the feed-conversion proxy is dry
 * matter eaten ÷ gain (as-fed where a feed has no DM %). These assumptions are shown with the figures.
 */
final class FeedCost
{
    public const ASSUMPTIONS = [
        'Feed cost = KG offered × the feed\'s cost per KG on the day it was given (refused and wasted feed is still a cost).',
        'Each meal\'s cost is split equally among the goats that were in the pen at that meal (from the movement history).',
        'Gain = latest weight on or before the last day − latest weight on or before the first day (or the first weight in the period).',
        'Feed conversion proxy = dry matter eaten (KG offered − refused − wasted, × DM %) ÷ KG gained; as-fed when a feed has no DM %.',
        'Value of gain uses the market rate per KG live weight in force on the last day (Herd Value). Fattening batches show their own costs; this view is the whole herd and is not posted to the accounts again.',
    ];

    /**
     * @return array{pens: Collection, goats: Collection, totals: array<string, mixed>}
     */
    public static function build(Carbon $from, Carbon $to): array
    {
        $usages = FeedUsage::current()->with('pen.shed')->whereBetween('fed_on', [$from->toDateString(), $to->toDateString()])->get();
        $dm = FeedItem::pluck('dm_pct', 'id')->map(fn ($v) => $v === null ? null : (float) $v);
        $penOn = self::penTimeline($from, $to);

        $goats = [];   // id => [cost, dm, headMeals, pens => [penId => headMeals]]
        $pens = [];    // penId => totals
        $unallocated = 0.0;
        foreach ($usages as $u) {
            $cost = $u->offered_kg * $u->unit_cost_minor / 100;
            $eaten = (float) $u->consumedKg();
            $dmKg = $eaten * (($dm[$u->feed_item_id] ?? null) !== null ? $dm[$u->feed_item_id] / 100 : 1);
            $p = &$pens[$u->pen_id];
            $p ??= ['id' => $u->pen_id, 'name' => $u->pen?->fullName() ?? 'Pen', 'cost' => 0.0, 'offered' => 0.0, 'refused' => 0.0, 'wasted' => 0.0, 'dm' => 0.0, 'days' => [], 'goats' => []];
            $p['cost'] += $cost;
            $p['offered'] += (float) $u->offered_kg;
            $p['refused'] += (float) ($u->refused_kg ?? 0);
            $p['wasted'] += (float) ($u->wasted_kg ?? 0);
            $p['dm'] += $dmKg;
            $day = $u->fed_on->toDateString();
            $p['days'][$day] = true;
            $here = $penOn[$day][$u->pen_id] ?? [];
            if (! $here) {
                $unallocated += $cost;
                unset($p);

                continue;
            }
            $n = count($here);
            foreach ($here as $id) {
                $g = &$goats[$id];
                $g ??= ['cost' => 0.0, 'dm' => 0.0, 'meals' => 0, 'days' => [], 'pens' => []];
                $g['cost'] += $cost / $n;
                $g['dm'] += $dmKg / $n;
                $g['meals']++;
                $g['days'][$day] = true;
                $g['pens'][$u->pen_id] = ($g['pens'][$u->pen_id] ?? 0) + 1;
                $p['goats'][$id] = true;
                unset($g);
            }
            unset($p);
        }

        $ids = array_keys($goats);
        $animals = Animal::with('breed')->whereIn('id', $ids)->get()->keyBy('id');
        $gains = self::gains($ids, $from, $to);
        $sick = HealthCase::whereIn('animal_id', $ids)->where('onset_on', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('resolved_on')->orWhere('resolved_on', '>=', $from->toDateString()))->where('condition', '!=', Intake::CONDITION)->pluck('animal_id')->unique()->flip();
        $value = new HerdValue;
        $alert = (float) (Tenant::org()?->fcr_alert ?? 10);

        $goatRows = collect($goats)->map(function ($g, $id) use ($animals, $gains, $value, $to, $sick) {
            $a = $animals[$id] ?? null;
            $gain = $gains[$id]['gain'] ?? null;
            $rate = $a ? $value->rateOn($to, $a->animal_purpose) : null;
            $worth = $gain !== null && $rate ? $gain * $rate / 100 : null;

            return [
                'id' => $id, 'number' => $a?->farm_number, 'breed' => $a?->breed?->name, 'days' => count($g['days']), 'cost' => round($g['cost']), 'costDay' => count($g['days']) ? round($g['cost'] / count($g['days']), 1) : null,
                'dm' => round($g['dm'], 1), 'start' => $gains[$id]['start'] ?? null, 'end' => $gains[$id]['end'] ?? null, 'gain' => $gain,
                'adg' => $gain !== null && ($gains[$id]['span'] ?? 0) > 0 ? (int) round($gain / $gains[$id]['span'] * 1000) : null,
                'costPerKg' => $gain && $gain > 0 ? round($g['cost'] / $gain) : null, 'fcr' => $gain && $gain > 0 ? round($g['dm'] / $gain, 1) : null,
                'valueGain' => $worth !== null ? round($worth) : null, 'margin' => $worth !== null ? round($worth - $g['cost']) : null, 'sick' => isset($sick[$id]),
                'pens' => $g['pens'],
            ];
        })->sortByDesc('cost')->values();

        $penRows = collect($pens)->map(function ($p) use ($goatRows, $alert) {
            // A goat that moved shares its gain between pens by the meals it ate in each.
            $mine = $goatRows->filter(fn ($g) => isset($p['goats'][$g['id']]));
            $gain = $mine->sum(fn ($g) => $g['gain'] !== null ? $g['gain'] * ($g['pens'][$p['id']] ?? 0) / max(1, array_sum($g['pens'])) : 0);
            $weighed = $mine->filter(fn ($g) => $g['gain'] !== null)->count();
            $fcr = $gain > 0 ? round($p['dm'] / $gain, 1) : null;
            $adgs = $mine->pluck('adg')->filter(fn ($v) => $v !== null);
            $sick = $mine->where('sick', true)->count();
            $issues = array_values(array_filter([
                $fcr !== null && $fcr > $alert ? "Feed conversion {$fcr} (alert above {$alert})" : null,
                $weighed && $gain <= 0 ? 'No weight gain' : null,
                $p['offered'] > 0 && ($p['refused'] + $p['wasted']) / $p['offered'] > 0.1 ? 'Refused / wasted '.round(($p['refused'] + $p['wasted']) / $p['offered'] * 100).'%' : null,
                $sick ? "{$sick} goats sick in the period" : null,
            ]));

            return [
                'id' => $p['id'], 'name' => $p['name'], 'cost' => round($p['cost']), 'offered' => round($p['offered'], 1), 'refusedPct' => $p['offered'] > 0 ? round(($p['refused'] + $p['wasted']) / $p['offered'] * 100, 1) : null,
                'dm' => round($p['dm'], 1), 'goats' => $mine->count(), 'weighed' => $weighed, 'days' => count($p['days']),
                'costHeadDay' => $mine->sum('days') ? round($p['cost'] / max(1, $mine->sum('days')), 1) : null,
                'gain' => round($gain, 1), 'costPerKg' => $gain > 0 ? round($p['cost'] / $gain) : null, 'fcr' => $fcr, 'adg' => $adgs->count() ? (int) round($adgs->avg()) : null,
                'margin' => $mine->whereNotNull('margin')->count() ? round($mine->sum('margin')) : null, 'sick' => $sick, 'issues' => $issues,
            ];
        })->sortByDesc('cost')->values();

        $cost = $penRows->sum('cost');
        $gain = $goatRows->sum(fn ($g) => max(0, $g['gain'] ?? 0));
        $offered = $penRows->sum('offered');
        $counts = FeedStockCount::whereBetween('counted_on', [$from->toDateString(), $to->toDateString()])->get();
        $stock = Feed::stock();
        $items = FeedItem::where('active', true)->get();
        $plans = Feed::plansOn(Feed::now()->toDateString());
        $groups = Feed::groupIds(Animal::active()->get())->countBy();
        $withGoats = $plans->filter(fn ($p, $gid) => ($groups[$gid] ?? 0) > 0)->count();

        return [
            'pens' => $penRows,
            'goats' => $goatRows->map(fn ($g) => collect($g)->except('pens')->all())->values(),
            'totals' => [
                'cost' => round($cost), 'unallocated' => round($unallocated), 'offered' => round($offered, 1), 'gain' => round($gain, 1),
                'costPerKg' => $gain > 0 ? round(($cost - $unallocated) / $gain) : null,
                'fcr' => $gain > 0 ? round($goatRows->sum('dm') / $gain, 1) : null,
                'refusedPct' => $offered > 0 ? round(($penRows->sum(fn ($p) => $p['offered'] * ($p['refusedPct'] ?? 0) / 100)) / $offered * 100, 1) : null,
                'cpCompliance' => $withGoats ? (int) round(($withGoats - Feed::lowCpRations()->count()) / $withGoats * 100) : null,
                'stockValue' => round($items->sum(fn ($i) => max(0, $stock[$i->id] ?? 0) * $i->cost_per_kg_minor / 100)),
                'shrinkKg' => round(-$counts->where('diff_kg', '<', 0)->sum('diff_kg'), 1), 'shrinkValue' => round(-$counts->where('diff_kg', '<', 0)->sum('value_minor') / 100),
                'shrinkPct' => $counts->sum('book_kg') > 0 ? round(-$counts->where('diff_kg', '<', 0)->sum('diff_kg') / $counts->sum('book_kg') * 100, 1) : null,
                'margin' => $goatRows->whereNotNull('margin')->count() ? round($goatRows->sum('margin')) : null,
                'fcrAlert' => $alert,
            ],
        ];
    }

    /**
     * Which goats were in which pen on each day of the period, from the movement history (pen of the last movement on or
     * before the day; before the first movement, the pen it moved from; with no movements, its pen now).
     *
     * @return array<string, array<int, array<int, string>>> date => pen id => goat ids
     */
    public static function penTimeline(Carbon $from, Carbon $to): array
    {
        $animals = Animal::where(fn ($q) => $q->where('status', 'active')->orWhere('exit_date', '>=', $from->toDateString()))->get(['id', 'pen_id', 'status', 'birth_date', 'acquired_on', 'exit_date']);
        $moves = AnimalMovement::whereIn('animal_id', $animals->modelKeys())->orderBy('moved_on')->orderBy('id')->get(['animal_id', 'moved_on', 'from_pen_id', 'to_pen_id', 'type'])->groupBy('animal_id');
        $out = [];
        foreach ($animals as $a) {
            $list = $moves[$a->id] ?? collect();
            $start = $a->birth_date && $a->birth_date->gt($from) ? $a->birth_date : ($a->acquired_on && $a->acquired_on->gt($from) ? $a->acquired_on : $from);
            for ($d = $start->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
                $last = $list->filter(fn ($m) => $m->moved_on->lte($d))->last();
                $pen = $last ? $last->to_pen_id : ($list->first()?->from_pen_id ?? ($a->status === 'active' ? $a->pen_id : null));
                if ($pen) {
                    $out[$d->toDateString()][$pen][] = $a->id;
                }
            }
        }

        return $out;
    }

    /** @return array<string, array{start: ?float, end: ?float, gain: ?float, span: int}> */
    private static function gains(array $ids, Carbon $from, Carbon $to): array
    {
        $out = [];
        foreach (WeightRecord::whereIn('animal_id', $ids)->where('measured_on', '<=', $to->toDateString())->orderBy('measured_on')->orderBy('id')->get()->groupBy('animal_id') as $id => $ws) {
            $start = $ws->filter(fn ($w) => $w->measured_on->lte($from))->last() ?? $ws->first(fn ($w) => $w->measured_on->gt($from));
            $end = $ws->last();
            $ok = $start && $end && $end->measured_on->gt($start->measured_on);
            $out[$id] = ['start' => $start?->weight_kg, 'end' => $end?->weight_kg, 'gain' => $ok ? round($end->weight_kg - $start->weight_kg, 1) : null,
                'span' => $ok ? (int) $start->measured_on->diffInDays($end->measured_on) : 0];
        }

        return $out;
    }
}
