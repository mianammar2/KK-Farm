<?php

namespace App\Support;

/** D-079/D-121: the feed requirement by shed, pen and goat (Feeding → Requirement) and its dashboard summary. */
class FeedRequirement
{
    /**
     * D-079: today's feed requirement for every goat, grouped by shed and pen: the goat's rule (e.g. 3.5% of 20 kg),
     * kg a day and the split over feeds; pen and shed totals with cost. Kids on milk and goats without a ration or a
     * weight are listed with the reason, so nothing is silently left out.
     */
    public static function build(\Illuminate\Support\Collection $goats): array
    {
        $goats->loadMissing('pen.shed');
        $req = \App\Support\Feed::perGoat($goats, \App\Support\Feed::now()->toDateString());
        $md = \App\Support\Feed::now()->daysInMonth;
        $items = \App\Models\FeedItem::all()->keyBy('id');
        $feedText = fn (array $lines) => collect($lines)->map(fn ($kg, $id) => ($items[$id]->name ?? '?').' '.round($kg, 2))->implode(' · ');
        $cost = fn (array $lines) => collect($lines)->sum(fn ($kg, $id) => $kg * ($items[$id]->cost_per_kg_minor ?? 0)) / 100;
        $sum = function ($rows) use ($items) {
            $lines = [];
            foreach ($rows as $r) {
                foreach ($r['lines'] as $id => $kg) {
                    $lines[$id] = ($lines[$id] ?? 0) + $kg;
                }
            }

            return collect($lines)->map(fn ($kg, $id) => ['name' => $items[$id]->name ?? '?', 'kg' => round($kg, 1)])->sortByDesc('kg')->values()->all();
        };

        // D-086: what each pen was given today against what its goats need now.
        $givenToday = \App\Models\FeedUsage::current()->where('fed_on', \App\Support\Feed::now()->toDateString())->groupBy('pen_id')->selectRaw('pen_id, sum(offered_kg) as kg')->pluck('kg', 'pen_id');
        $tol = (float) \App\Support\Feed::org()->feed_tolerance_pct;
        $sheds = $goats->groupBy(fn ($g) => $g->pen?->shed_id ?? 0)->map(function ($inShed, $shedId) use ($req, $feedText, $cost, $sum, $md, $givenToday, $tol) {
            $shed = $inShed->first()->pen?->shed;
            $pens = $inShed->groupBy(fn ($g) => $g->pen_id ?? 0)->map(function ($inPen) use ($req, $feedText, $cost, $md, $givenToday, $tol) {
                $rows = $inPen->map(fn ($g) => $req[$g->id] + ['id' => $g->id, 'number' => $g->farm_number])->sortBy('number')->values();
                $given = round((float) ($givenToday[$inPen->first()->pen_id] ?? 0), 2);
                $bal = \App\Support\Feed::balance($rows->sum('kgDay'), $rows->sum('min'), $rows->sum('max'), $given, $tol);

                return [
                    'id' => $inPen->first()->pen_id, 'name' => $inPen->first()->pen?->fullName() ?? 'Not in a pen', 'kgDay' => round($rows->sum('kgDay'), 1), 'cpDay' => round($rows->sum('cpKg'), 2), 'kgMonth' => round($rows->sum('kgDay') * $md, 1), 'givenToday' => $given, 'balance' => $bal['state'], 'diff' => $bal['diff'],
                    'cost' => round($rows->sum(fn ($r) => $cost($r['lines']))), 'count' => $rows->count(), 'unplanned' => $rows->where('state', '!=', 'planned')->count(),
                    'goats' => $rows->map(fn ($r) => [
                        'id' => $r['id'], 'number' => $r['number'], 'group' => $r['group'], 'weight' => $r['weight'], 'state' => $r['state'], 'rule' => $r['rule'],
                        'kgDay' => $r['kgDay'], 'kgMonth' => round($r['kgDay'] * $md, 1), 'cp' => $r['cp'], 'cpKg' => $r['cpKg'], 'cpStandard' => $r['standard'] ?? null, 'min' => $r['min'], 'max' => $r['max'], 'feeds' => $feedText($r['lines']),
                    ])->values()->all(),
                    '_rows' => $rows,
                ];
            })->sortBy('name')->values();
            $all = $pens->flatMap(fn ($p) => $p['_rows']);

            return [
                'id' => $shedId ?: null, 'name' => $shed?->name ?? 'Not in a pen', 'typeName' => $shed?->typeName(), 'kgDay' => round($all->sum('kgDay'), 1), 'cpDay' => round($all->sum('cpKg'), 2), 'kgMonth' => round($all->sum('kgDay') * $md, 1),
                'cost' => round($all->sum(fn ($r) => $cost($r['lines']))), 'count' => $all->count(), 'feeds' => $sum($all),
                'pens' => $pens->map(fn ($p) => collect($p)->except('_rows')->all())->all(),
            ];
        })->sortBy(fn ($s) => [$s['id'] === null ? 1 : 0, $s['name']])->values();

        // D-080: need against the feed store. Per feed where a ration splits the need; overall for the rest.
        $stock = \App\Support\Feed::stock();
        $active = $items->where('active', true);
        $needByFeed = [];
        foreach ($req as $r) {
            foreach ($r['lines'] as $id => $kg) {
                $needByFeed[$id] = ($needByFeed[$id] ?? 0) + $kg;
            }
        }
        $kgDay = round($req->sum('kgDay'), 2);
        $inStore = round($active->sum(fn ($i) => max(0, $stock[$i->id] ?? 0)), 1);
        // D-081: this calendar month in the farm's time zone: the whole month's need, and what is still needed from today to month end.
        $now = \App\Support\Feed::now();
        $monthDays = $now->daysInMonth;
        $daysLeft = $monthDays - $now->day + 1;
        $supply = [
            'needDay' => round($kgDay, 1), 'needWeek' => round($kgDay * 7, 1), 'inStore' => $inStore,
            // D-111: crude protein the day's feed should carry (feed KG × CP % for each goat's age); null when no CP % is set.
            'cpDay' => $req->contains(fn ($r) => $r['cp'] !== null) ? round($req->sum('cpKg'), 2) : null, 'cpMonth' => $req->contains(fn ($r) => $r['cp'] !== null) ? round($req->sum('cpKg') * $monthDays, 1) : null,
            'cpMissing' => $req->where('state', 'planned')->whereNull('cp')->count(),
            'month' => $now->format('m/Y'), 'monthDays' => $monthDays, 'daysLeft' => $daysLeft,
            'needMonth' => round($kgDay * $monthDays, 1), 'needLeft' => round($kgDay * $daysLeft, 1), 'shortMonth' => round(max(0, $kgDay * $daysLeft - $inStore), 1),
            'days' => $kgDay > 0 ? (int) floor($inStore / $kgDay) : null,
            'unsplit' => round($req->where('state', 'planned')->where('split', false)->sum('kgDay'), 1),
            'feeds' => collect($needByFeed)->map(fn ($need, $id) => [
                'name' => $items[$id]->name ?? '?', 'needDay' => round($need, 1), 'stock' => round(max(0, $stock[$id] ?? 0), 1),
                'days' => $need > 0 ? (int) floor(max(0, $stock[$id] ?? 0) / $need) : null, 'shortWeek' => round(max(0, $need * 7 - max(0, $stock[$id] ?? 0)), 1),
                'needMonth' => round($need * $monthDays, 1), 'shortMonth' => round(max(0, $need * $daysLeft - max(0, $stock[$id] ?? 0)), 1),
            ])->sortBy('days')->values()->all(),
        ];

        return [
            'supply' => $supply, 'tracking' => (bool) (\App\Support\Tenant::org()?->feed_tracking ?? true), // D-107
            'kgDay' => round($req->sum('kgDay'), 1), 'cost' => round($req->sum(fn ($r) => $cost($r['lines']))), 'feeds' => $sum($req->values()),
            'kgMonth' => round($req->sum('kgDay') * $monthDays, 1), 'costMonth' => round($req->sum(fn ($r) => $cost($r['lines'])) * $monthDays),
            'planned' => $req->where('state', 'planned')->count(), 'milk' => $req->where('state', 'milk')->count(),
            'missing' => $req->whereIn('state', ['no_ration', 'no_weight'])->count(), 'sheds' => $sheds->all(),
        ];
    }

    /** D-121: the dashboard's view: totals and supply, then sheds and their pens with goats, KG and CP a day. */
    public static function summary(array $r): array
    {
        return [
            'supply' => $r['supply'], 'tracking' => $r['tracking'], 'kgDay' => $r['kgDay'], 'cost' => $r['cost'], 'kgMonth' => $r['kgMonth'], 'costMonth' => $r['costMonth'],
            'planned' => $r['planned'], 'milk' => $r['milk'], 'missing' => $r['missing'],
            'sheds' => collect($r['sheds'])->map(fn ($s) => ['id' => $s['id'], 'name' => $s['name'], 'typeName' => $s['typeName'], 'kgDay' => $s['kgDay'], 'cpDay' => $s['cpDay'], 'count' => $s['count'],
                'pens' => collect($s['pens'])->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'count' => $p['count'], 'kgDay' => $p['kgDay'], 'cpDay' => $p['cpDay'], 'unplanned' => $p['unplanned'], 'balance' => $p['balance'], 'diff' => $p['diff']])->all()])->all(),
        ];
    }
}
