<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\FeedingGroup;
use App\Models\FeedItem;
use App\Models\FeedStockMovement;
use App\Models\FeedUsage;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RationPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * D-054 feed rules.
 *
 * - A goat's feeding group follows its classification automatically (or a group set on the goat by hand).
 * - Planned kg for a pen and meal = Σ over the goats in the pen of (their group's approved kg/head/day × the meal's %).
 * - Stock = Σ stock movements − Σ feed offered (refused feed still left the store, as in the workbook).
 * - A meal is Done, Late (fed more than meal_late_minutes after the planned time), Missed (nothing recorded within
 *   meal_window_minutes) or still Due/Upcoming. Nothing is typed for compliance: it comes from the time of the entry.
 */
final class Feed
{
    public const MEALS = ['morning' => 'Morning', 'midday' => 'Midday', 'evening' => 'Evening'];

    public const DEFAULT_TIMES = ['morning' => '07:00', 'midday' => '12:00', 'evening' => '17:00'];

    /** Workbook ration groups, plus Growers and Fattening for KK Farms' categories. code => [name, who is in it]. */
    public const GROUPS = [
        'kids' => ['Kids', 'Kids (male and female) until weaning and grower age'],
        'growers' => ['Growers and Replacements', 'Growing goats and future breeders'],
        'breeding_does' => ['Breeding Does', 'Breeding females that are not pregnant or lactating'],
        'pregnant_does' => ['Pregnant Does', 'Pregnant or close to kidding'],
        'lactating_does' => ['Lactating Does', 'Kidded, nursing or lactating'],
        'breeding_bucks' => ['Breeding Bucks', 'Breeding males'],
        'fattening' => ['Fattening', 'Fattening, wether and cull goats'],
        'quarantine' => ['Quarantine', 'Sick or in quarantine'],
        'other' => ['Other', 'Anyone not matched above'],
    ];

    public static function seedGroups(string $orgId): void
    {
        $sort = 0;
        foreach (self::GROUPS as $code => [$name, $criteria]) {
            $sort += 10;
            DB::table('feeding_groups')->insertOrIgnore([
                'org_id' => $orgId, 'code' => $code, 'name' => $name, 'criteria' => $criteria, 'sort' => $sort,
                'plan_status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** The automatic group for a goat, from its health, female status and category. */
    public static function groupCode(Animal $a): string
    {
        $category = $a->effectiveCategory();

        return match (true) {
            in_array($a->health_flag, ['sick', 'quarantine'], true) => 'quarantine',
            in_array($category, ['male_kid', 'female_kid'], true) || $a->effectiveAge() === 'kid' => 'kids',
            in_array($a->repro_status, ['pregnant', 'expected_kidding'], true) => 'pregnant_does',
            in_array($a->repro_status, ['kidded', 'lactating', 'nursing'], true) => 'lactating_does',
            $category === 'breeding_male' => 'breeding_bucks',
            $category === 'breeding_female' => 'breeding_does',
            in_array($category, ['replacement_male', 'replacement_female', 'growing_male', 'growing_female'], true) => 'growers',
            in_array($category, ['fattening_male', 'wether', 'cull'], true) => 'fattening',
            default => 'other',
        };
    }

    /** @return Collection<string, int> group id for each goat */
    public static function groupIds(Collection $animals): Collection
    {
        $byCode = FeedingGroup::whereNotNull('code')->pluck('id', 'code');

        return $animals->mapWithKeys(fn (Animal $a) => [$a->id => $a->feeding_group_id ?? $byCode[self::groupCode($a)] ?? null]);
    }

    /** @return Collection<int, RationPlan> the approved plan in force on a date, by group id */
    public static function plansOn(Carbon|string $date): Collection
    {
        $day = Carbon::parse($date)->toDateString();

        return RationPlan::with('lines.item')->where('status', 'approved')->where('effective_from', '<=', $day)
            ->orderBy('effective_from')->orderBy('id')->get()->keyBy('feeding_group_id'); // later plans overwrite earlier ones
    }

    /**
     * What each pen should get at a meal.
     *
     * A per-head ration gives each goat kg_per_head of each feed. A body-weight ration (D-067) gives each goat
     * latest weight × the intake % for its age (grower 2%, adult 3% by default) per day, split over the feeds by share.
     * Goats without a ration — or, on a body-weight ration, without a weight or an intake % for their age — are unplanned.
     *
     * @return Collection<int, array{headcount: int, unplanned: int, noWeight: int, lines: array<int, float>}> by pen id; lines are kg by feed item id
     */
    public static function plannedByPen(string $meal, Carbon|string $date, ?Collection $plans = null): Collection
    {
        $plans ??= self::plansOn($date);
        $org = self::org();
        $animals = Animal::active()->whereNotNull('pen_id')->get();
        $groups = self::groupIds($animals);
        $weights = self::latestWeights();

        return $animals->groupBy('pen_id')->map(function (Collection $goats) use ($groups, $plans, $meal, $org, $weights) {
            $lines = [];
            $low = [];
            $high = [];
            $unplanned = 0;
            $noWeight = 0;
            $onMilk = 0;
            $add = function (int $item, float $kg, float $min, float $max) use (&$lines, &$low, &$high) {
                $lines[$item] = ($lines[$item] ?? 0) + $kg;
                $low[$item] = ($low[$item] ?? 0) + $min;
                $high[$item] = ($high[$item] ?? 0) + $max;
            };
            foreach ($goats as $goat) {
                $plan = $plans[$groups[$goat->id]] ?? null;
                $range = self::intakeRange($goat, $org);
                // D-069: kids drink milk. Without a ration of their own they are "on milk", not missing a ration.
                if ($goat->effectiveAge() === 'kid' && ! $range && (! $plan || $plan->basis === 'body_weight' || $plan->lines->isEmpty())) {
                    $onMilk++;

                    continue;
                }
                if (! $plan || $plan->lines->isEmpty()) {
                    $unplanned++;

                    continue;
                }
                if ($plan->basis === 'body_weight') {
                    $weight = $weights[$goat->id] ?? null;
                    if (! $range || ! $weight) {
                        $unplanned++;
                        if (! $weight) {
                            $noWeight++;
                        }

                        continue;
                    }
                    // Planned at the middle of the farm's range (grower 3–4% → 3.5%), with the range kept for checking.
                    [$min, $max] = $range;
                    foreach ($plan->lines as $line) {
                        $f = $weight / 100 * $line->share_pct / 100 * $plan->split($meal) / 100;
                        $add($line->feed_item_id, $f * ($min + $max) / 2, $f * $min, $f * $max);
                    }

                    continue;
                }
                foreach ($plan->lines as $line) {
                    $kg = $line->kg_per_head * $plan->split($meal) / 100;
                    $add($line->feed_item_id, $kg, $kg, $kg);
                }
            }
            $round = fn (array $a) => array_map(fn ($kg) => round($kg, 2), $a);

            return [
                'headcount' => $goats->count(), 'unplanned' => $unplanned, 'noWeight' => $noWeight, 'onMilk' => $onMilk,
                'lines' => $round($lines), 'min' => $round($low), 'max' => $round($high),
            ];
        });
    }

    /** @return Collection<string, float> each goat's most recent weight in kg */
    public static function latestWeights(): Collection
    {
        return DB::table('weight_records')->selectRaw('distinct on (animal_id) animal_id, weight_kg')
            ->orderBy('animal_id')->orderByDesc('measured_on')->orderByDesc('id')->pluck('weight_kg', 'animal_id')->map(fn ($kg) => (float) $kg);
    }

    /**
     * D-069: daily intake as % of body weight for the goat's age, as a [min, max] range: growers 3–4%, adults 2–3%
     * (unknown age counts as adult). Kids drink milk: null unless the farm sets a kid range.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function intakeRange(Animal $goat, Organization $org): ?array
    {
        [$min, $max] = match ($goat->effectiveAge()) {
            'kid' => [$org->kid_intake_min_pct, $org->kid_intake_max_pct],
            'grower' => [$org->grower_intake_min_pct, $org->grower_intake_max_pct],
            default => [$org->adult_intake_min_pct, $org->adult_intake_max_pct],
        };

        return $min !== null && $max !== null ? [(float) $min, (float) $max] : null;
    }

    /** @return Collection<int, float> kg in store by feed item id */
    public static function stock(): Collection
    {
        $in = FeedStockMovement::groupBy('feed_item_id')->selectRaw('feed_item_id, sum(qty_kg) as kg')->pluck('kg', 'feed_item_id');
        $out = FeedUsage::current()->groupBy('feed_item_id')->selectRaw('feed_item_id, sum(offered_kg) as kg')->pluck('kg', 'feed_item_id');

        return FeedItem::pluck('id')->mapWithKeys(fn ($id) => [$id => round((float) ($in[$id] ?? 0) - (float) ($out[$id] ?? 0), 2)]);
    }

    public static function stockStatus(float $kg, float $reorder): string
    {
        return match (true) {
            $kg < 0 => 'negative',
            $kg == 0.0 => 'out',
            $kg <= $reorder => 'low',
            default => 'ok',
        };
    }


    /**
     * Every pen with goats, for every meal of a day: planned kg per feed, what was recorded and the meal status.
     *
     * @return Collection<int, array<string, mixed>> by pen id
     */
    public static function board(string $date, Organization $org): Collection
    {
        $plans = self::plansOn($date);
        $planned = collect(array_keys(self::MEALS))->mapWithKeys(fn ($meal) => [$meal => self::plannedByPen($meal, $date, $plans)]);
        $usage = FeedUsage::current()->where('fed_on', $date)->get()->groupBy(fn (FeedUsage $u) => "{$u->pen_id}|{$u->meal}");
        // D-069: only pens that have goats now (a pen emptied after feeding drops off the board; its feed stays in history).
        $penIds = $planned->flatMap(fn ($byPen) => $byPen->keys())->unique();

        return $penIds->mapWithKeys(function ($penId) use ($planned, $usage, $date, $org) {
            $meals = [];
            foreach (array_keys(self::MEALS) as $meal) {
                $plan = $planned[$meal][$penId] ?? ['headcount' => 0, 'unplanned' => 0, 'noWeight' => 0, 'onMilk' => 0, 'lines' => [], 'min' => [], 'max' => []];
                $rows = $usage["$penId|$meal"] ?? collect();
                $first = $rows->min('fed_at');
                $meals[$meal] = [
                    'headcount' => $plan['headcount'], 'unplanned' => $plan['unplanned'], 'noWeight' => $plan['noWeight'], 'onMilk' => $plan['onMilk'],
                    'lines' => $plan['lines'], 'min' => $plan['min'], 'max' => $plan['max'],
                    'plannedKg' => round(array_sum($plan['lines']), 2), 'rows' => $rows,
                    'status' => self::mealStatus($date, $meal, $first ? Carbon::parse($first) : null, array_sum($plan['lines']), $org),
                ];
            }

            return [$penId => $meals];
        });
    }

    /** @return array{expected: int, done: int, late: int, missed: int} meals due so far today, across pens */
    public static function todayCompliance(Organization $org): array
    {
        $counts = ['expected' => 0, 'done' => 0, 'late' => 0, 'missed' => 0];
        foreach (self::board(self::now($org)->toDateString(), $org) as $meals) {
            foreach ($meals as $m) {
                if (in_array($m['status'], ['done', 'late', 'missed', 'due'], true)) {
                    $counts['expected']++;
                }
                if (isset($counts[$m['status']])) {
                    $counts[$m['status']]++;
                }
            }
        }

        return $counts;
    }

    // ----- farm clock and compliance -----

    public static function org(): Organization
    {
        return Organization::findOrFail(Tenant::current());
    }

    public static function now(?Organization $org = null): Carbon
    {
        return now()->setTimezone(($org ?? self::org())->timezone ?: 'Asia/Karachi');
    }

    /** @return array<string, string> meal => "HH:MM" */
    public static function mealTimes(?Organization $org = null): array
    {
        return array_merge(self::DEFAULT_TIMES, ($org ?? self::org())->meal_times ?? []);
    }

    /** The meal whose time has most recently started (before the first meal: morning). */
    public static function currentMeal(?Organization $org = null): string
    {
        $org ??= self::org();
        $clock = self::now($org)->format('H:i');
        $current = 'morning';
        foreach (self::mealTimes($org) as $meal => $time) {
            if ($clock >= $time) {
                $current = $meal;
            }
        }

        return $current;
    }

    public static function plannedAt(string $date, string $meal, Organization $org): Carbon
    {
        return Carbon::parse("$date ".self::mealTimes($org)[$meal], $org->timezone ?: 'Asia/Karachi');
    }

    /** done | late | missed | due | upcoming | none */
    public static function mealStatus(string $date, string $meal, ?Carbon $fedAt, float $plannedKg, Organization $org): string
    {
        $planned = self::plannedAt($date, $meal, $org);
        if ($fedAt) {
            return $fedAt->greaterThan($planned->copy()->addMinutes($org->meal_late_minutes)) ? 'late' : 'done';
        }
        if ($plannedKg <= 0) {
            return 'none';
        }
        $now = now();

        return match (true) {
            $now->greaterThan($planned->copy()->addMinutes($org->meal_window_minutes)) => 'missed',
            $now->greaterThanOrEqualTo($planned) => 'due',
            default => 'upcoming',
        };
    }
}
