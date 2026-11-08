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
        $active = Animal::active();
        $today = now()->toDateString();

        $wall = Pen::with(['animals' => fn ($q) => $q->active()->orderBy('farm_number')])->orderBy('name')->get()->map(fn (Pen $pen) => [
            'id' => $pen->id, 'name' => $pen->name, 'purpose' => $pen->purpose, 'capacity' => $pen->capacity,
            'goats' => $pen->animals->map(fn (Animal $a) => [
                'id' => $a->id, 'number' => $a->farm_number, 'name' => $a->name, 'sex' => $a->sex, 'coat' => $a->coat, 'health' => $a->health_status, 'repro' => $a->repro_state,
            ]),
        ]);

        $overdue = HealthRecord::whereIn('kind', ['vaccination', 'deworming'])->where('next_due_on', '<', $today)
            ->whereHas('animal', fn ($q) => $q->active())->distinct('animal_id')->count('animal_id');

        $attention = HealthRecord::with('animal')->where('status', 'open')->whereHas('animal', fn ($q) => $q->active())
            ->orderByRaw("case severity when 'high' then 0 when 'medium' then 1 else 2 end")->orderByDesc('occurred_on')->limit(6)->get()
            ->map(fn (HealthRecord $r) => ['id' => $r->id, 'goatId' => $r->animal_id, 'number' => $r->animal->farm_number, 'name' => $r->animal->name, 'coat' => $r->animal->coat, 'sex' => $r->animal->sex, 'health' => $r->animal->health_status, 'title' => $r->title, 'on' => $r->occurred_on->format('j M')]);

        $kiddings = Mating::with('dam')->where('status', 'pregnant')->orderBy('expected_kidding_on')->limit(5)->get()
            ->map(fn (Mating $m) => ['goatId' => $m->dam_id, 'number' => $m->dam->farm_number, 'name' => $m->dam->name, 'on' => $m->expected_kidding_on->format('j M'), 'days' => (int) now()->startOfDay()->diffInDays($m->expected_kidding_on, false)]);

        $feeding = \App\Support\Feed::todayCompliance(\App\Support\Feed::org()); // D-054: meals due so far today, across pens

        return Inertia::render('Dashboard', [
            'stats' => [
                'goats' => (clone $active)->count(),
                'does' => (clone $active)->where('sex', 'female')->count(),
                'bucks' => (clone $active)->where('sex', 'male')->count(),
                'pregnant' => (clone $active)->where('repro_state', 'pregnant')->count(),
                'unwell' => (clone $active)->where('health_status', '!=', 'healthy')->count(),
                'overdueCare' => $overdue,
                'tasksDone' => Task::where('due_on', $today)->where('status', 'done')->count(),
                'tasksTotal' => Task::where('due_on', $today)->count(),
                'feedingDone' => $feeding['done'] + $feeding['late'],
                'feedingTotal' => $feeding['expected'],
            ],
            'herd' => $this->herdCategories(),
            'wall' => $wall,
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
    private function herdCategories(): array
    {
        // Worked out per goat with the live category (D-047): fine for thousands of goats, and never stale.
        $goats = Animal::active()->get(['id', 'sex', 'birth_date', 'age_category', 'animal_class', 'animal_purpose', 'repro_status', 'repro_state', 'health_flag', 'health_status', 'kid_stage']);
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
}
