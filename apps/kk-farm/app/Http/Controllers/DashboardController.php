<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Animal;
use App\Models\HealthRecord;
use App\Models\LedgerEntry;
use App\Models\Mating;
use App\Models\Pen;
use App\Models\Task;
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

        // D-070: one source for the headline numbers, the same rules as "Herd by category".
        $goats = Animal::active()->get(['id', 'sex', 'birth_date', 'age_category', 'animal_class', 'animal_purpose', 'repro_status', 'repro_state', 'health_flag', 'health_status', 'kid_stage']);
        $unwell = $goats->filter(fn (Animal $a) => in_array($a->health_flag, ['monitor', 'sick', 'quarantine', 'recovering'], true) || ($a->health_status && $a->health_status !== 'healthy'))->count();
        $pregnant = $goats->filter(fn (Animal $a) => $a->sex === 'female' && (in_array($a->repro_status, ['pregnant', 'expected_kidding'], true) || $a->repro_state === 'pregnant'))->count();

        $overdue = HealthRecord::whereIn('kind', ['vaccination', 'deworming'])->where('next_due_on', '<', $today)
            ->whereHas('animal', fn ($q) => $q->active())->distinct('animal_id')->count('animal_id');

        $attention = HealthRecord::with('animal')->where('status', 'open')->whereHas('animal', fn ($q) => $q->active())
            ->orderByRaw("case severity when 'high' then 0 when 'medium' then 1 else 2 end")->orderByDesc('occurred_on')->limit(6)->get()
            ->map(fn (HealthRecord $r) => ['id' => $r->id, 'goatId' => $r->animal_id, 'number' => $r->animal->farm_number, 'name' => $r->animal->name, 'coat' => $r->animal->coat, 'sex' => $r->animal->sex, 'health' => $r->animal->health_status, 'title' => $r->title, 'on' => $r->occurred_on->format('j M')]);

        $kiddings = Mating::with('dam')->where('status', 'pregnant')->orderBy('expected_kidding_on')->limit(5)->get()
            ->map(fn (Mating $m) => ['goatId' => $m->dam_id, 'number' => $m->dam->farm_number, 'name' => $m->dam->name, 'on' => $m->expected_kidding_on->format('j M'), 'days' => (int) now()->startOfDay()->diffInDays($m->expected_kidding_on, false)]);

        $feedToday = $this->feedingToday(); // D-069: feed section, pens that have goats
        $feeding = $feedToday['compliance'];

        return Inertia::render('Dashboard', [
            'stats' => [
                'goats' => $goats->count(),
                'does' => $goats->where('sex', 'female')->count(),
                'bucks' => $goats->where('sex', 'male')->count(),
                'pregnant' => $pregnant,
                'unwell' => $unwell,
                'overdueCare' => $overdue,
                'tasksDone' => Task::where('due_on', $today)->where('status', 'done')->count(),
                'tasksTotal' => Task::where('due_on', $today)->count(),
                'feedingDone' => $feeding['done'] + $feeding['late'],
                'feedingTotal' => $feeding['expected'],
            ],
            'herd' => $this->herdCategories($goats),
            'feeding' => $user->canModule('feeding') ? $feedToday : null,
            'attention' => $attention,
            'kiddings' => $kiddings,
            'activity' => fn () => Activity::latest()->limit(8)
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
     * CEO dashboard categories (D-046), counted over goats on the farm. A goat without a category yet is
     * counted as a kid from its age category and gender, so the numbers are useful before every goat is classified.
     *
     * @return list<array{key: string, label: string, value: int}>
     */
    private function herdCategories(\Illuminate\Support\Collection $goats): array
    {
        // Worked out per goat with the live category (D-047): fine for thousands of goats, and never stale.
        $count = fn (callable $test) => $goats->filter($test)->count();
        $cat = fn (Animal $a) => $a->effectiveCategory();
        $female = fn (Animal $a) => $a->sex === 'female';

        $values = [
            'total' => $goats->count(),
            'breeding_males' => $count(fn ($a) => $cat($a) === 'breeding_male'),
            'breeding_females' => $count(fn ($a) => $cat($a) === 'breeding_female'),
            'pregnant' => $count(fn ($a) => $female($a) && (in_array($a->repro_status, ['pregnant', 'expected_kidding'], true) || $a->repro_state === 'pregnant')),
            'ready' => $count(fn ($a) => $female($a) && $a->repro_status === 'ready'),
            'male_kids' => $count(fn ($a) => $cat($a) === 'male_kid'),
            'female_kids' => $count(fn ($a) => $cat($a) === 'female_kid'),
            'growing' => $count(fn ($a) => in_array($cat($a), ['growing_male', 'growing_female', 'replacement_male', 'replacement_female'], true)),
            'lactating' => $count(fn ($a) => $female($a) && (in_array($a->repro_status, ['lactating', 'nursing'], true) || $a->repro_state === 'lactating')),
            'sick' => $count(fn ($a) => $a->health_flag === 'sick' || $a->health_status === 'critical'),
            'treatment' => $count(fn ($a) => $a->health_status === 'treatment' || $a->health_flag === 'quarantine'),
            'for_sale' => $count(fn ($a) => $a->animal_purpose === 'sale' || in_array($cat($a), ['fattening_male', 'cull'], true) || $a->kid_stage === 'sale_selected'),
        ];

        return collect([
            'total' => 'Total Goats', 'breeding_males' => 'Breeding Male Goats', 'breeding_females' => 'Breeding Female Goats',
            'pregnant' => 'Pregnant Females', 'ready' => 'Ready for Breeding', 'male_kids' => 'Male Kids', 'female_kids' => 'Female Kids',
            'growing' => 'Growing Goats', 'lactating' => 'Lactating Females', 'sick' => 'Sick Goats', 'treatment' => 'Goats Under Treatment',
            'for_sale' => 'Goats Ready for Sale',
        ])->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'value' => $values[$key]])->values()->all();
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
                $out[$meal] = ['status' => $m['status'], 'planned' => $m['plannedKg'], 'given' => round($kg, 2)];
            }
            $first = reset($meals);

            return ['id' => $penId, 'name' => $pens[$penId]?->fullName() ?? 'Pen', 'goats' => $first['headcount'], 'onMilk' => $first['onMilk'], 'meals' => $out];
        })->sortBy('name')->values();

        $stock = \App\Support\Feed::stock();

        return [
            'meal' => \App\Support\Feed::currentMeal($org), 'meals' => \App\Support\Feed::MEALS, 'times' => \App\Support\Feed::mealTimes($org),
            'pens' => $rows, 'plannedKg' => round($planned, 1), 'givenKg' => round($given, 1), 'cost' => round($cost),
            'lowStock' => \App\Models\FeedItem::where('active', true)->get()->filter(fn ($i) => \App\Support\Feed::stockStatus($stock[$i->id] ?? 0, $i->reorder_level_kg) !== 'ok')->pluck('name')->values(),
            'compliance' => $compliance,
        ];
    }
}
