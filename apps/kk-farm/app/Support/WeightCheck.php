<?php

namespace App\Support;

use App\Models\WeightRecord;
use Illuminate\Support\Carbon;

/**
 * D-208 / BR-018 (UAT-18): every weight, whatever the channel (form, gun, scale sensor, checkup, kidding), is checked
 * when it is written. An impossible reading, a repeated scale reading, two different weights the same day, or a change
 * no goat makes between weighings is kept but held for review: it does not count in ADG, growth or costing until a
 * supervisor accepts it. A rejected reading stays on record (append-only) and never counts.
 */
class WeightCheck
{
    public const FLAGS = ['impossible' => 'Impossible weight', 'duplicate' => 'Repeated reading', 'conflict' => 'Two weights the same day', 'jump' => 'Gain too fast', 'drop' => 'Sudden loss'];

    /** @return array{0: string, 1: string}|null [flag, note] */
    public static function assess(string $animalId, $on, float $kg): ?array
    {
        if ($kg <= 0.3 || $kg > 150) {
            return ['impossible', "{$kg} KG is outside what a goat weighs."];
        }
        $on = Carbon::parse($on)->startOfDay();
        $same = WeightRecord::where('animal_id', $animalId)->whereDate('measured_on', $on)->get();
        if ($same->contains(fn ($w) => abs($w->weight_kg - $kg) < 0.05)) {
            return ['duplicate', "{$kg} KG was already recorded for this goat on {$on->format('d/m')}."];
        }
        if ($other = $same->first(fn ($w) => abs($w->weight_kg - $kg) > max(1, $w->weight_kg * 0.1))) {
            return ['conflict', "{$other->weight_kg} KG was recorded the same day."];
        }
        $prev = WeightRecord::where('animal_id', $animalId)->whereDate('measured_on', '<', $on)->orderByDesc('measured_on')->orderByDesc('id')->first();
        if ($prev && $prev->weight_kg > 0) {
            $days = max(1, (int) $prev->measured_on->diffInDays($on));
            $change = $kg - $prev->weight_kg;
            if ($change > max(2, 0.5 * $days) && $change / $prev->weight_kg > 0.25) {
                return ['jump', "+{$change} KG in {$days} days since {$prev->weight_kg} KG on {$prev->measured_on->format('d/m')}."];
            }
            if (-$change > max(2, 0.3 * $days) && -$change / $prev->weight_kg > 0.2) {
                return ['drop', round($change, 2)." KG in {$days} days since {$prev->weight_kg} KG on {$prev->measured_on->format('d/m')}."];
            }
        }

        return null;
    }
}
