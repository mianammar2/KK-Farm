<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\WeightRecord;
use Carbon\CarbonInterface;

/**
 * D-110: when the teeth are not known, a bought or transferred goat's age is a guess from its weight. Below the
 * breed's "light" line it is about 7 months (the farm's 6 to 8), otherwise about 10 (no adult teeth, so under 12).
 * Only the first weighing sets it; teeth or an edited birth date replace it later.
 */
class AgeFromWeight
{
    public const LIGHT_MONTHS = 7;

    public const HEAVIER_MONTHS = 10;

    /** Months for this weight, or null when the breed has no light line. */
    public static function months(Animal $goat, float $kg): ?int
    {
        $line = $goat->breed?->young_below_kg;

        return $line === null ? null : ($kg < (float) $line ? self::LIGHT_MONTHS : self::HEAVIER_MONTHS);
    }

    /** Does this goat still need an age? No birth date, no teeth, not farm-born. */
    public static function needsAge(Animal $goat): bool
    {
        return ! $goat->birth_date && ! $goat->teeth && $goat->origin !== 'born_on_farm';
    }

    public static function fromRecord(WeightRecord $record): void
    {
        $goat = Animal::with('breed')->find($record->animal_id);
        if ($goat && self::needsAge($goat) && $record->weight_kg) {
            self::apply($goat, (float) $record->weight_kg, $record->measured_on ?? now(), $record->measured_by ?: 'System');
        }
    }

    /** Set the estimated birth date: the weighing day minus the months. Returns the months, or null if none. */
    public static function apply(Animal $goat, float $kg, CarbonInterface $on, string $by): ?int
    {
        $months = self::months($goat, $kg);
        if ($months === null) {
            return null;
        }
        $goat->update(['birth_date' => $on->copy()->subMonths($months)->toDateString(), 'birth_date_is_estimate' => true, 'age_category' => null]);
        $line = rtrim(rtrim(number_format((float) $goat->breed->young_below_kg, 1), '0'), '.');
        Farm::event($goat, 'edit', sprintf('Age estimated from weight: %s KG on %s is %s %s KG for %s, so about %d months then', rtrim(rtrim(number_format($kg, 1), '0'), '.'),
            $on->format('j M Y'), $kg < (float) $goat->breed->young_below_kg ? 'under' : 'at or over', $line, $goat->breed->name, $months), $by);

        return $months;
    }
}
