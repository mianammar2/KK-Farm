<?php

namespace App\Support;

use App\Models\AgeStage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * D-221: the farm's age stages (Settings → Age Groups). A goat's stage follows from its age (birth date), so it moves
 * on by itself as the goat grows. A farm with none yet gets the KK Farms stages on first use.
 */
final class AgeStages
{
    /** code => [category, counts as, from months, to months, teeth stage key] */
    public const DEFAULTS = [
        'YKD' => ['Young Kid', 'kid', 0, 0.5, 'milk'],
        'KID' => ['Kid', 'kid', 0.5, 2, 'milk'],
        'GRW' => ['Grower', 'grower', 2, 14, 'milk'],
        'ADT-T02' => ['Adult', 'adult', 14, 18, '2'],
        'ADT-T04' => ['Adult', 'adult', 18, 24, '4'],
        'ADT-T06' => ['Adult', 'adult', 24, 30, '6'],
        'ADT-T08' => ['Adult', 'adult', 30, 48, '8'],
        'ADT-WBT' => ['Old', 'adult', 48, null, 'worn'],
    ];

    /** @return Collection<int, AgeStage> */
    public static function stages(): Collection
    {
        return Tenant::memo('age_stages', function () {
            if (! Tenant::current()) {
                return collect(self::DEFAULTS)->map(fn ($d, $c) => new AgeStage(['code' => $c, 'label' => $d[0], 'age_group' => $d[1], 'from_months' => $d[2], 'to_months' => $d[3], 'teeth_key' => $d[4]]))->values();
            }
            if (! AgeStage::exists()) {
                $i = 0;
                foreach (self::DEFAULTS as $c => $d) {
                    AgeStage::create(['code' => $c, 'label' => $d[0], 'age_group' => $d[1], 'from_months' => $d[2], 'to_months' => $d[3], 'teeth_key' => $d[4], 'sort' => ($i += 10)]);
                }
            }

            return AgeStage::orderBy('from_months')->orderBy('sort')->get();
        });
    }

    /** Age in months with fractions (0.5 month = about 15 days). */
    public static function months(CarbonInterface $birth, ?CarbonInterface $at = null): float
    {
        return $birth->diffInDays($at ?? now()) / 30.4375;
    }

    public static function forMonths(?float $months): ?AgeStage
    {
        return $months === null ? null : self::stages()->first(fn (AgeStage $s) => $s->contains($months));
    }

    /**
     * After the stages change, the Kid / Grower / Adult boundaries follow them: Grower starts at the first stage that
     * counts as Grower, Adult at the first that counts as Adult.
     */
    public static function syncGroups(): void
    {
        $s = AgeStage::all();
        $grower = $s->where('age_group', 'grower')->min('from_months');
        $adult = $s->where('age_group', 'adult')->min('from_months');
        $org = \App\Models\Organization::find(Tenant::current());
        if ($org && $grower !== null && $adult !== null && $adult > $grower) {
            $org->update(['grower_from_months' => (int) round($grower), 'adult_from_months' => (int) round($adult)]);
            Classification::forgetBoundaries();
        }
    }
}
