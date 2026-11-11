<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\GrowthIntervention;
use App\Models\GrowthTarget;
use App\Models\HealthCase;
use App\Models\WeightRecord;
use Illuminate\Support\Collection;

/**
 * D-191: growth plan and breeder eligibility (V3 journey B, BR-005).
 * Target weight at an age comes from the breed's growth plan, else the farm-wide plan, else these defaults
 * (KK Farms starting values, editable in Growth Plans); between two points it is interpolated.
 */
final class Growth
{
    /** age in days => target KG */
    public const DEFAULTS = [0 => 3.0, 30 => 7.0, 60 => 11.0, 90 => 15.0, 180 => 24.0, 270 => 30.0, 365 => 36.0];

    /** @var array<string, array<int, float>> */
    private static array $plans = [];

    public static function flush(): void
    {
        self::$plans = [];
    }

    /** @return array<int, float> the plan used for a breed */
    public static function plan(?int $breedId): array
    {
        $key = (string) $breedId;
        if (! isset(self::$plans[$key])) {
            $rows = GrowthTarget::where('breed_id', $breedId)->orderBy('age_days')->get();
            if ($rows->isEmpty() && $breedId) {
                $rows = GrowthTarget::whereNull('breed_id')->orderBy('age_days')->get();
            }
            self::$plans[$key] = $rows->isEmpty() ? self::DEFAULTS : $rows->pluck('target_kg', 'age_days')->map(fn ($v) => (float) $v)->all();
        }

        return self::$plans[$key];
    }

    public static function target(?int $breedId, int $ageDays): ?float
    {
        $plan = self::plan($breedId);
        $ages = array_keys($plan);
        if (! $ages || $ageDays > max($ages)) {
            return null; // beyond the plan: no target
        }
        $prev = null;
        foreach ($plan as $age => $kg) {
            if ($ageDays === $age) {
                return $kg;
            }
            if ($ageDays < $age) {
                if ($prev === null) {
                    return $kg;
                }
                [$a0, $k0] = $prev;

                return round($k0 + ($kg - $k0) * ($ageDays - $a0) / ($age - $a0), 1);
            }
            $prev = [$age, $kg];
        }

        return null;
    }

    /** @return Collection<string, WeightRecord> the latest weight per animal */
    public static function latest(array $ids): Collection
    {
        return WeightRecord::whereIn('animal_id', $ids)->orderBy('measured_on')->orderBy('id')->get()->keyBy('animal_id');
    }

    /**
     * Growth status of a young goat: latest weight, age when weighed, target then, % of target, ADG since birth.
     *
     * @return array{weight: ?float, on: ?string, age: int, target: ?float, pct: ?int, adg: ?int, below: bool, stale: bool}
     */
    public static function status(Animal $a, ?WeightRecord $w, int $alertPct): array
    {
        $age = $a->birth_date ? (int) $a->birth_date->diffInDays(now()) : 0;
        if (! $w || ! $a->birth_date) {
            return ['weight' => null, 'on' => null, 'age' => $age, 'target' => $a->birth_date ? self::target($a->breed_id, $age) : null, 'pct' => null, 'adg' => null, 'below' => false, 'stale' => true];
        }
        $ageAt = (int) $a->birth_date->diffInDays($w->measured_on);
        $target = self::target($a->breed_id, $ageAt);
        $pct = $target ? (int) round($w->weight_kg / $target * 100) : null;
        $birth = (float) ($a->birth_weight_kg ?: (self::plan($a->breed_id)[0] ?? 3.0));
        $adg = $ageAt > 0 ? (int) round(($w->weight_kg - $birth) / $ageAt * 1000) : null;

        return ['weight' => $w->weight_kg, 'on' => $w->measured_on->format('j M Y'), 'age' => $age, 'target' => $target, 'pct' => $pct, 'adg' => $adg,
            'below' => $pct !== null && $pct < $alertPct, 'stale' => $w->measured_on->lt(now()->subDays(30))];
    }

    /**
     * BR-005 breeding selection checks: maturity, growth, body condition, health and known pedigree.
     *
     * @return array<string, array{ok: bool, label: string}>
     */
    public static function eligibility(Animal $a, ?WeightRecord $w, int $selectionAge, int $alertPct): array
    {
        $s = self::status($a, $w, $alertPct);
        $bcs = WeightRecord::where('animal_id', $a->id)->whereNotNull('body_score')->latest('measured_on')->value('body_score');
        $sick = HealthCase::where('animal_id', $a->id)->whereIn('status', HealthCase::ACTIVE)->exists();
        $open = GrowthIntervention::where('animal_id', $a->id)->where('status', 'open')->exists();

        return [
            'age' => ['ok' => $s['age'] >= $selectionAge, 'label' => "Age {$s['age']} days (from {$selectionAge})"],
            'growth' => ['ok' => $s['pct'] !== null && $s['pct'] >= 100 - (100 - $alertPct) / 2 && ! $s['stale'], 'label' => $s['pct'] === null ? 'No weight' : "{$s['weight']} KG = {$s['pct']}% of target".($s['stale'] ? ' (old weight)' : '')],
            'bcs' => ['ok' => $bcs === null || $bcs >= 3, 'label' => $bcs === null ? 'BCS not scored' : "BCS {$bcs}"],
            'health' => ['ok' => ! $sick && ! $open && ($a->health_status ?? 'healthy') === 'healthy', 'label' => $sick ? 'Open health case' : ($open ? 'Growth intervention open' : 'Healthy')],
            'pedigree' => ['ok' => (bool) ($a->sire_id || $a->sire_tag) && (bool) ($a->dam_id || $a->dam_tag), 'label' => ($a->sire_id || $a->sire_tag) && ($a->dam_id || $a->dam_tag) ? 'Sire and dam known' : 'Parent unknown'],
        ];
    }
}
