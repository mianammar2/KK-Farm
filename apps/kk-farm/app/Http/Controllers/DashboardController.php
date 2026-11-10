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
        $goats = Animal::with(['pen.shed', 'breed'])->active()->orderBy('farm_number')->get();
        $tags = HerdStats::tagsFor($goats);
        $counts = collect(array_keys(HerdStats::LABELS))->mapWithKeys(fn ($k) => [$k => $tags->filter(fn ($t) => in_array($k, $t, true))->count()]);
        $byId = $goats->keyBy('id');
        $card = fn (Animal $a) => ['goatId' => $a->id, 'number' => $a->farm_number, 'name' => $a->name, 'coat' => $a->coat, 'breed' => $a->breed?->name, 'stage' => $a->effectiveAge(), 'sex' => $a->sex, 'health' => $a->health_status, 'flag' => $a->health_flag];

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

        $tracking = (bool) (\App\Support\Tenant::org()?->feed_tracking ?? true); // D-107
        $feedToday = $tracking ? $this->feedingToday() : ['compliance' => ['expected' => 0, 'done' => 0, 'late' => 0, 'missed' => 0]]; // D-069: feed section, pens that have goats
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
            // D-121: actions are in the notification bell (/notifications), not on the dashboard.
            'herd' => collect(['total', ...HerdStats::CATEGORIES])->map(fn ($k) => ['key' => $k, 'label' => HerdStats::LABELS[$k], 'value' => $counts[$k]])->values(),
            'herdAge' => $this->herdByAge($goats, $tags), // D-118
            'missing' => $missing,
            'feeding' => $user->canModule('feeding') && $tracking ? $feedToday : null,
            'feedTracking' => $tracking,
            'requirement' => $user->canModule('feeding') ? \App\Support\FeedRequirement::summary(\App\Support\FeedRequirement::build($goats)) : null, // D-121: detail on Feeding → Requirement
            'pensSummary' => $user->canModule('sheds') || $user->canModule('goats') ? $this->pensSummary($goats) : null, // D-120: moving goats is on the Sheds page
            // D-121: health shortcuts are on Veterinary & Health.
            'attention' => $attention,
            'kiddings' => $kiddings,
            // D-122: recent activity is on the Activity Log page.
            'breeds' => DB::table('animals')->join('breeds', 'breeds.id', '=', 'animals.breed_id')->where('status', 'active')
                ->select('breeds.name', DB::raw('count(*) as value'))->groupBy('breeds.name')->orderByDesc('value')->get(),
            'growth' => DB::table('weight_records')->where('measured_on', '>=', now()->subMonths(8)->startOfMonth())
                ->selectRaw("to_char(measured_on, 'YYYY-MM') as month, round(avg(weight_kg)::numeric, 1)::float as kg")->groupBy('month')->orderBy('month')->get(),
            'finance' => $user->canModule('finance') ? $this->financeSummary() : null,
        ]);
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
     * D-118: the herd by age band (kid, grower, adult, from each goat's birth date and the farm's age settings) and
     * gender, and by breed and gender. Each count opens exactly those goats (/goats?show=KEY).
     */
    private function herdByAge(\Illuminate\Support\Collection $goats, \Illuminate\Support\Collection $tags): array
    {
        [$growerFrom, $adultFrom] = \App\Support\Classification::boundaries();
        $count = fn (string $key) => $tags->filter(fn ($t) => in_array($key, $t, true))->count();
        $bands = ['kid' => "Under {$growerFrom} months", 'grower' => "{$growerFrom} to {$adultFrom} months", 'adult' => "{$adultFrom} months and over", 'age_unknown' => 'No birth date or teeth yet'];
        $rows = collect($bands)->map(fn ($range, $age) => ['key' => $age, 'label' => HerdStats::LABELS[$age], 'range' => $range,
            'female' => $count("{$age}_female"), 'male' => $count("{$age}_male"), 'total' => $count($age)])
            ->filter(fn ($r) => $r['key'] !== 'age_unknown' || $r['total'] > 0)->values();

        return [
            'rows' => $rows, 'female' => $count('female'), 'male' => $count('male'), 'total' => $goats->count(),
            // D-123: by classification (goat category, set or suggested from gender, age and purpose).
            'classes' => $goats->groupBy(fn ($g) => $g->effectiveCategory() ?? 'none')->map(fn ($gs, $c) => ['key' => 'class_'.$c,
                'label' => \App\Support\Classification::CATEGORIES[$c][0] ?? 'Not Classified', 'local' => \App\Support\Classification::CATEGORIES[$c][1] ?? null,
                'female' => $gs->where('sex', 'female')->count(), 'male' => $gs->where('sex', '!=', 'female')->count(), 'total' => $gs->count()])->sortBy([['total', 'desc'], ['label', 'asc']])->values(),
        ];
    }

    /**
     * D-120: the dashboard's sheds and pens at a glance: only sheds and pens with goats, each pen's count (and
     * capacity), females and males, and goats needing attention. A pen opens its goats in the Goat Registry.
     */
    private function pensSummary(\Illuminate\Support\Collection $goats): array
    {
        $byPen = $goats->whereNotNull('pen_id')->groupBy('pen_id');
        $pens = \App\Models\Pen::with('shed')->whereIn('id', $byPen->keys())->get();

        return [
            'sheds' => $pens->groupBy('shed_id')->map(fn ($ps) => [
                'id' => $ps->first()->shed->id, 'name' => $ps->first()->shed->name, 'typeName' => $ps->first()->shed->typeName(),
                'goats' => $ps->sum(fn ($p) => $byPen[$p->id]->count()),
                'pens' => $ps->sortBy('name')->map(fn ($p) => ['id' => $p->id, 'name' => $p->fullName(), 'code' => $p->name, 'capacity' => $p->capacity,
                    'goats' => $byPen[$p->id]->count(), 'female' => $byPen[$p->id]->where('sex', 'female')->count(), 'male' => $byPen[$p->id]->where('sex', '!=', 'female')->count(),
                    'attention' => $byPen[$p->id]->where('health_flag', '!=', 'healthy')->whereNotNull('health_flag')->count(),
                    // D-147: up to 18 goats to draw (females first): gender, kid/grower/adult, breed, needs attention
                    'figures' => $byPen[$p->id]->sortBy(fn ($g) => $g->sex === 'female' ? 0 : 1)->take(18)->map(fn ($g) => [
                        'female' => $g->sex === 'female', 'stage' => $g->effectiveAge(), 'breed' => $g->breed?->name,
                        'flag' => $g->health_flag !== null && $g->health_flag !== 'healthy',
                    ])->values()->all()])->values()->all(),
            ])->sortBy('name')->values()->all(),
            'noPen' => $goats->whereNull('pen_id')->count(),
        ];
    }
}
