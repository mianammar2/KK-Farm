<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Animal;
use App\Models\FeedingGroup;
use App\Models\FeedingLog;
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

        $feedingDone = FeedingLog::where('fed_on', $today)->count();

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
                'feedingDone' => $feedingDone,
                'feedingTotal' => FeedingGroup::count() * 2,
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
        $r = DB::table('animals')->where('status', 'active')->selectRaw(<<<'SQL'
            count(*) AS total,
            count(*) FILTER (WHERE animal_class = 'breeding_male') AS breeding_males,
            count(*) FILTER (WHERE animal_class = 'breeding_female') AS breeding_females,
            count(*) FILTER (WHERE sex = 'female' AND (repro_status IN ('pregnant', 'expected_kidding') OR repro_state = 'pregnant')) AS pregnant,
            count(*) FILTER (WHERE sex = 'female' AND repro_status = 'ready') AS ready,
            count(*) FILTER (WHERE animal_class = 'male_kid' OR (animal_class IS NULL AND sex = 'male' AND age_category = 'kid')) AS male_kids,
            count(*) FILTER (WHERE animal_class = 'female_kid' OR (animal_class IS NULL AND sex = 'female' AND age_category = 'kid')) AS female_kids,
            count(*) FILTER (WHERE animal_class IN ('growing_male', 'growing_female', 'replacement_male', 'replacement_female')) AS growing,
            count(*) FILTER (WHERE sex = 'female' AND (repro_status IN ('lactating', 'nursing') OR repro_state = 'lactating')) AS lactating,
            count(*) FILTER (WHERE health_flag = 'sick' OR health_status = 'critical') AS sick,
            count(*) FILTER (WHERE health_status = 'treatment' OR health_flag = 'quarantine') AS treatment,
            count(*) FILTER (WHERE animal_purpose = 'sale' OR animal_class IN ('fattening_male', 'cull') OR kid_stage = 'sale_selected') AS for_sale
        SQL)->first();

        return collect([
            'total' => 'Total Goats', 'breeding_males' => 'Breeding Male Goats', 'breeding_females' => 'Breeding Female Goats',
            'pregnant' => 'Pregnant Females', 'ready' => 'Ready for Breeding', 'male_kids' => 'Male Kids', 'female_kids' => 'Female Kids',
            'growing' => 'Growing Goats', 'lactating' => 'Lactating Females', 'sick' => 'Sick Goats', 'treatment' => 'Goats Under Treatment',
            'for_sale' => 'Goats Ready for Sale',
        ])->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'value' => (int) $r->{$key}])->values()->all();
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
