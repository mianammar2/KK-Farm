<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\HealthCase;
use App\Models\HealthRecord;
use Illuminate\Support\Carbon;

/**
 * D-347: a goat that leaves the farm (sold, died, slaughtered, culled, gifted, transferred) takes its health work with it.
 * Its open, under-treatment or monitoring cases are closed on the exit date (Deceased when it died) with a note saying why,
 * and its open health reports are closed, so the vets' lists and counts only hold goats that are still on the farm.
 * The case keeps its history; nothing is deleted.
 */
class ExitHealth
{
    public static function close(Animal $animal): int
    {
        $on = Carbon::parse($animal->exit_date ?? now());
        $why = trim(($animal->exit_reason ?: $animal->status).'');
        $note = "Closed: the goat left the farm ({$why}) on {$on->format('d/m/Y')}.";
        $n = 0;
        foreach (HealthCase::where('animal_id', $animal->id)->whereIn('status', HealthCase::ACTIVE)->get() as $case) {
            $case->update([
                'status' => $animal->status === 'deceased' ? 'deceased' : 'closed',
                'resolved_on' => $case->resolved_on ?? $on->toDateString(),
                'notes' => trim(($case->notes ? $case->notes."\n" : '').$note),
            ]);
            $n++;
        }
        HealthRecord::where('animal_id', $animal->id)->where('status', 'open')->update(['status' => 'closed']);
        if ($n) {
            Farm::event($animal, 'health', "{$n} health case".($n > 1 ? 's' : '').' closed: the goat left the farm', 'System', $on);
        }

        return $n;
    }
}
