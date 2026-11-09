<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Animal;
use App\Models\HealthRecord;
use App\Models\LedgerEntry;
use App\Models\Mating;
use App\Models\Pen;
use App\Models\Task;
use App\Support\HerdStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $today = now()->toDateString();

        // D-071: every number comes from HerdStats, the same rules the Goat Registry filters with (/goats?show=KEY).
        $goats = Animal::with(['pen.shed'])->active()->orderBy('farm_number')->get();
        $tags = HerdStats::tagsFor($goats);
        $counts = collect(array_keys(HerdStats::LABELS))->mapWithKeys(fn ($k) => [$k => $tags->filter(fn ($t) => in_array($k, $t, true))->count()]);
        $byId = $goats->keyBy('id');
        $card = fn (Animal $a) => ['goatId' => $a->id, 'number' => $a->farm_number, 'name' => $a->name, 'coat' => $a->coat, 'sex' => $a->sex, 'health' => $a->health_status, 'flag' => $a->health_flag];

        $overdue = HealthRecord::whereIn('kind', ['vaccination', 'deworming'])->where('next_due_on', '<', $today)
            ->whereHas('animal', fn ($q) => $q->active())->distinct('animal_id')->count('animal_id');

        // Needs attention = goats whose health is not Healthy, with their latest open report. Open reports on goats
        // marked Healthy are counted separately ("to review"), so a Healthy goat is never listed as needing attention.
        $open = HealthRecord::where('status', 'open')->whereIn('animal_id', $goats->modelKeys())->orderByDesc('occurred_on')->orderByDesc('id')->get()->groupBy('animal_id');
        $attentionIds = $tags->filter(fn ($t) => in_array('attention', $t, true))->keys();
        $attention = $attentionIds->map(fn ($id) => $card($byId[$id]) + [
            'title' => $open[$id][0]->title ?? null, 'on' => isset($open[$id]) ? $open[$id][0]->occurred_on->format('j M') : null,
        ])->values();
        $toReview = $open->keys()->diff($attentionIds)->sum(fn ($id) => $open[$id]->count());

        // Goats missing what the farm needs to manage them, and where each record came from.
        $origins = DB::table('animal_events')->where('type', 'origin')->whereIn('animal_id', $goats->modelKeys())->orderBy('occurred_at')->get()->unique('animal_id')->keyBy('animal_id');
        $missing = $tags->filter(fn ($t) => in_array('missing', $t, true))->keys()->map(function ($id) use ($byId, $tags, $origins, $card) {
            $o = $origins[$id] ?? null;

            return $card($byId[$id]) + [
                'missing' => array_values(array_map(fn ($k) => HerdStats::LABELS[$k], array_intersect(['no_pen', 'no_weight', 'age_conflict'], $tags[$id]))),
                'source' => $o ? $o->summary.' · '.$o->recorded_by.' · '.\Illuminate\Support\Carbon::parse($o->occurred_at)->format('j M') : 'Added '.$byId[$id]->created_at->format('j M'),
            ];
        })->values();

        $kiddings = Mating::with('dam')->where('status', 'pregnant')->orderBy('expected_kidding_on')->limit(20)->get()
            ->map(fn (Mating $m) => ['goatId' => $m->dam_id, 'number' => $m->dam->farm_number, 'name' => $m->dam->name, 'on' => $m->expected_kidding_on->format('j M'), 'days' => (int) now()->startOfDay()->diffInDays($m->expected_kidding_on, false)]);

        $feedToday = $this->feedingToday(); // D-069: feed section, pens that have goats
        $feeding = $feedToday['compliance'];

        return Inertia::render('Dashboard', [
            'stats' => [
                'goats' => $goats->count(),
                'does' => $goats->where('sex', 'female')->count(),
                'bucks' => $goats->where('sex', 'male')->count(),
                'pregnant' => $counts['pregnant'],
                'unwell' => $counts['attention'],
                'noPen' => $counts['no_pen'],
                'missing' => $counts['missing'],
                'toReview' => $toReview,
                'overdueCare' => $overdue,
                'tasksDone' => Task::where('due_on', $today)->where('status', 'completed')->count(),
                'tasksTotal' => Task::where('due_on', $today)->where('status', '!=', 'cancelled')->count(),
                'feedingDone' => $feeding['done'] + $feeding['late'],
                'feedingTotal' => $feeding['expected'],
            ],
            // D-077: the Action Center in one line on the dashboard.
            'actions' => fn () => collect(\App\Support\ActionCenter::items($user))->map(fn ($i) => ['key' => $i['key'], 'label' => $i['label'], 'count' => $i['count'], 'severity' => $i['severity']])->values(),
            'herd' => collect(['total', ...HerdStats::CATEGORIES])->map(fn ($k) => ['key' => $k, 'label' => HerdStats::LABELS[$k], 'value' => $counts[$k]])->values(),
            'missing' => $missing,
            'feeding' => $user->canModule('feeding') ? $feedToday : null,
            'requirement' => $user->canModule('feeding') ? $this->feedRequirement($goats) : null,
            'shedBoard' => $user->canModule('sheds') || $user->canModule('goats') ? $this->shedBoard($goats) : null,
            'canMove' => $user->canModule('sheds'),
            'attention' => $attention,
            'kiddings' => $kiddings,
            'activity' => fn () => Activity::latest()->limit(20)
                ->when(! $user->canModule('finance'), fn ($q) => $q->where('audience', 'farm'))->get()
                ->map(fn (Activity $a) => ['id' => $a->id, 'actor' => $a->actor, 'role' => $a->role, 'action' => $a->action, 'url' => $a->url, 'ago' => $a->created_at->diffForHumans(short: true)]),
            'breeds' => DB::table('animals')->join('breeds', 'breeds.id', '=', 'animals.breed_id')->where('status', 'active')
                ->select('breeds.name', DB::raw('count(*) as value'))->groupBy('breeds.name')->orderByDesc('value')->get(),
            'growth' => DB::table('weight_records')->where('measured_on', '>=', now()->subMonths(8)->startOfMonth())
                ->selectRaw("to_char(measured_on, 'YYYY-MM') as month, round(avg(weight_kg)::numeric, 1)::float as kg")->groupBy('month')->orderBy('month')->get(),
            'finance' => $user->canModule('finance') ? $this->financeSummary() : null,
        ]);
    }

    /**
     * D-085: every shed with all its pens (empty ones too, so goats can be moved into them) and the goats in each pen,
     * plus goats that have no pen. Moves go through Movements::transfer, so each one is a movement row and a life event.
     */
    private function shedBoard(\Illuminate\Support\Collection $goats): array
    {
        $weights = \App\Support\Feed::latestWeights();
        $out = \App\Support\Sensors::outNow(); // D-087: goats out in an open area now
        $card = fn (Animal $a) => [
            'id' => $a->id, 'number' => $a->farm_number, 'name' => $a->name, 'sex' => $a->sex, 'age' => $a->effectiveAge(), 'ageLabel' => $a->ageLabel(),
            'flag' => $a->health_flag, 'weight' => isset($weights[$a->id]) ? round($weights[$a->id], 1) : null,
            'out' => isset($out[$a->id]) ? ['area' => $out[$a->id]->area?->fullName(), 'since' => $out[$a->id]->at->diffForHumans(short: true)] : null,
        ];
        $byPen = $goats->groupBy(fn ($g) => $g->pen_id ?? 0);
        $byId = $goats->keyBy('id');
        $sheds = \App\Models\Shed::with(['pens' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get()
            ->each(fn (\App\Models\Shed $s) => $s->pens->each->setRelation('shed', $s))->map(fn (\App\Models\Shed $s) => [
            'id' => $s->id, 'name' => $s->name, 'typeName' => $s->typeName(),
            'pens' => $s->pens->where('kind', 'pen')->map(fn (\App\Models\Pen $p) => [
                'id' => $p->id, 'name' => $p->fullName(), 'code' => $p->name, 'purpose' => $p->purpose, 'capacity' => $p->capacity,
                'goats' => ($byPen[$p->id] ?? collect())->map($card)->values()->all(),
            ])->values()->all(),
            'areas' => $s->pens->where('kind', 'open_area')->map(fn (\App\Models\Pen $p) => [
                'id' => $p->id, 'name' => $p->fullName(), 'code' => $p->name, 'purpose' => $p->purpose, 'capacity' => $p->capacity,
                'goats' => $out->where('area_id', $p->id)->map(fn ($x) => $byId[$x->animal_id] ?? null)->filter()->map($card)->values()->all(),
            ])->values()->all(),
        ])->values()->all();

        return ['sheds' => $sheds, 'noPen' => ($byPen[0] ?? collect())->map($card)->values()->all()];
    }

    /** @return array<int, array{month: string, in: float, out: float}> */
    private function financeSummary(): array
    {
        return LedgerEntry::where('occurred_on', '>=', now()->subMonths(5)->startOfMonth())
            ->selectRaw("to_char(occurred_on, 'YYYY-MM') as month, sum(case when direction = 'in' then amount else 0 end)::float as income, sum(case when direction = 'out' then amount else 0 end)::float as spend")
            ->groupBy('month')->orderBy('month')->get()
            ->map(fn ($r) => ['month' => $r->month, 'in' => $r->income, 'out' => $r->spend])->all();
    }

    /** D-069: today's feeding for the dashboard: every pen that has goats, each meal's status, planned and given kg. */
    private function feedingToday(): array
    {
        $org = \App\Support\Feed::org();
        $today = \App\Support\Feed::now($org)->toDateString();
        $board = \App\Support\Feed::board($today, $org);
        $pens = \App\Models\Pen::with('shed')->whereIn('id', $board->keys())->get()->keyBy('id');
        $compliance = ['expected' => 0, 'done' => 0, 'late' => 0, 'missed' => 0];
        $planned = $given = $cost = 0.0;

        $rows = $board->map(function (array $meals, int $penId) use ($pens, &$compliance, &$planned, &$given, &$cost) {
            $out = [];
            foreach ($meals as $meal => $m) {
                $kg = (float) $m['rows']->sum('offered_kg');
                $planned += $m['plannedKg'];
                $given += $kg;
                $cost += $m['rows']->sum(fn ($u) => $u->offered_kg * $u->unit_cost_minor / 100);
                $compliance['expected'] += in_array($m['status'], ['done', 'late', 'missed', 'due'], true) ? 1 : 0;
                if (isset($compliance[$m['status']])) {
                    $compliance[$m['status']]++;
                }
                $out[$meal] = ['status' => $m['status'], 'planned' => $m['plannedKg'], 'given' => round($kg, 2), 'balance' => $m['balance']['state'], 'diff' => $m['balance']['diff']];
            }
            $first = reset($meals);

            $dayPlan = array_sum(array_column($meals, 'plannedKg'));
            $dayGiven = array_sum(array_column($meals, 'givenKg'));
            $day = \App\Support\Feed::balance($dayPlan, array_sum(array_column($meals, 'minKg')), array_sum(array_column($meals, 'maxKg')), $dayGiven, (float) \App\Support\Feed::org()->feed_tolerance_pct);

            return ['id' => $penId, 'name' => $pens[$penId]?->fullName() ?? 'Pen', 'goats' => $first['headcount'], 'onMilk' => $first['onMilk'], 'meals' => $out,
                'dayPlanned' => round($dayPlan, 2), 'dayGiven' => round($dayGiven, 2), 'over' => $day['state'] === 'over' || collect($out)->contains('balance', 'over'), 'diff' => $day['diff']];
        })->sortBy('name')->values();

        $stock = \App\Support\Feed::stock();

        return [
            'meal' => \App\Support\Feed::currentMeal($org), 'meals' => \App\Support\Feed::MEALS, 'times' => \App\Support\Feed::mealTimes($org),
            'pens' => $rows, 'plannedKg' => round($planned, 1), 'givenKg' => round($given, 1), 'cost' => round($cost),
            'lowStock' => \App\Models\FeedItem::where('active', true)->get()->filter(fn ($i) => \App\Support\Feed::stockStatus($stock[$i->id] ?? 0, $i->reorder_level_kg) !== 'ok')->pluck('name')->values(),
            'compliance' => $compliance,
            'overPens' => $rows->where('over', true)->count(),
        ];
    }

    /**
     * D-079: today's feed requirement for every goat, grouped by shed and pen: the goat's rule (e.g. 3.5% of 20 kg),
     * kg a day and the split over feeds; pen and shed totals with cost. Kids on milk and goats without a ration or a
     * weight are listed with the reason, so nothing is silently left out.
     */
    private function feedRequirement(\Illuminate\Support\Collection $goats): array
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
                    'id' => $inPen->first()->pen_id, 'name' => $inPen->first()->pen?->fullName() ?? 'Not in a pen', 'kgDay' => round($rows->sum('kgDay'), 1), 'kgMonth' => round($rows->sum('kgDay') * $md, 1), 'givenToday' => $given, 'balance' => $bal['state'], 'diff' => $bal['diff'],
                    'cost' => round($rows->sum(fn ($r) => $cost($r['lines']))), 'count' => $rows->count(), 'unplanned' => $rows->where('state', '!=', 'planned')->count(),
                    'goats' => $rows->map(fn ($r) => [
                        'id' => $r['id'], 'number' => $r['number'], 'group' => $r['group'], 'weight' => $r['weight'], 'state' => $r['state'], 'rule' => $r['rule'],
                        'kgDay' => $r['kgDay'], 'kgMonth' => round($r['kgDay'] * $md, 1), 'min' => $r['min'], 'max' => $r['max'], 'feeds' => $feedText($r['lines']),
                    ])->values()->all(),
                    '_rows' => $rows,
                ];
            })->sortBy('name')->values();
            $all = $pens->flatMap(fn ($p) => $p['_rows']);

            return [
                'id' => $shedId ?: null, 'name' => $shed?->name ?? 'Not in a pen', 'typeName' => $shed?->typeName(), 'kgDay' => round($all->sum('kgDay'), 1), 'kgMonth' => round($all->sum('kgDay') * $md, 1),
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
            'month' => $now->format('F'), 'monthDays' => $monthDays, 'daysLeft' => $daysLeft,
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
            'supply' => $supply,
            'kgDay' => round($req->sum('kgDay'), 1), 'cost' => round($req->sum(fn ($r) => $cost($r['lines']))), 'feeds' => $sum($req->values()),
            'kgMonth' => round($req->sum('kgDay') * $monthDays, 1), 'costMonth' => round($req->sum(fn ($r) => $cost($r['lines'])) * $monthDays),
            'planned' => $req->where('state', 'planned')->count(), 'milk' => $req->where('state', 'milk')->count(),
            'missing' => $req->whereIn('state', ['no_ration', 'no_weight'])->count(), 'sheds' => $sheds->all(),
        ];
    }
}
