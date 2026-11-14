<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\HealthCase;
use App\Models\HealthCheckup;
use App\Models\HealthRecord;
use App\Models\Task;
use App\Models\WeightRecord;
use Illuminate\Support\Carbon;

/**
 * D-250: a goat leaves "Watch" when its own measurements say it has recovered, so the list only holds goats that are
 * not improving.
 *
 * A watch raised by a measurement (a checkup that found a weight drop, a temperature outside normal, or a Watch/Sick
 * result without a case, and the vet review of poor growth) is answered by the measurements taken after it:
 *   - weight drop: a later weighing back to within 3% of the weight before the drop (or above it);
 *   - temperature: a later checkup with the temperature inside the normal range;
 *   - a Watch result: a later weighing above the weight on the day of the watch (and any temperature taken since
 *     normal), or a later checkup that says Healthy;
 *   - a Sick result, or a serious one: a later checkup that says Healthy (weight alone does not clear a sick goat);
 *   - poor growth: the latest weight back at or above the farm's growth alert level.
 * Every part of the watch must be answered. The record is closed with the reason written on it and a line in the goat's
 * history, and the vet's "Vet check" task for it is completed. Anyone can open a new watch (a checkup or a report).
 *
 * Never closed by this: reports raised by people (a caretaker's concern), anything tied to a health case (the vet
 * closes cases), and a watch with no later measurement. The system records; the vet decides on cases.
 */
final class Recovery
{
    public const TOLERANCE_PCT = Checkups::WEIGHT_DROP_PCT; // back within 3% of the weight before the drop

    /** @return list<string> the reasons for each watch closed */
    public static function review(Animal $goat, string $by = 'System'): array
    {
        $open = self::openWatches($goat);
        $closed = [];
        foreach ($open as $record) {
            $why = self::answered($goat, $record);
            if ($why === null) {
                continue;
            }
            $record->update(['status' => 'closed', 'notes' => trim(($record->notes ? "{$record->notes}\n" : '')."Cleared automatically: {$why}.")]);
            Task::where('animal_id', $goat->id)->whereIn('status', Task::OPEN)->where('title', 'like', "Vet check {$goat->farm_number}:%")
                ->where('created_at', '<=', $record->created_at->copy()->addMinute())
                ->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $by, 'notes' => "Cleared: {$why}."]);
            Farm::event($goat, 'health', "Out of Watch: {$why}", $by);
            $closed[] = $why;
        }
        if ($closed) {
            Checkups::syncHealth($goat->fresh());
        }

        return $closed;
    }

    /** Why this watch is answered by later measurements, or null while it is not. */
    private static function answered(Animal $goat, HealthRecord $r): ?string
    {
        $since = $r->created_at;
        $after = fn ($q, string $date) => $q->where(fn ($w) => $w->whereDate($date, '>', $r->occurred_on)->orWhere(fn ($x) => $x->whereDate($date, $r->occurred_on)->where('created_at', '>', $since)));
        $reasons = [];

        if ($r->title === 'Poor growth: veterinary review') {
            $w = $after(WeightRecord::where('animal_id', $goat->id), 'measured_on')->orderByDesc('measured_on')->orderByDesc('id')->first();
            if (! $w) {
                return null;
            }
            $alert = (int) (\App\Models\Organization::find($goat->org_id)?->growth_alert_pct ?? 85);
            $s = Growth::status($goat, $w, $alert);
            if ($s['pct'] === null || $s['below']) {
                return null;
            }

            return "growth back on track ({$s['weight']} KG = {$s['pct']}% of the {$s['target']} KG target on {$s['on']})";
        }

        $title = $r->title;
        $checks = $after(HealthCheckup::where('animal_id', $goat->id), 'checked_on')->orderByDesc('checked_on')->orderByDesc('id');
        $latestWeight = fn () => $after(WeightRecord::where('animal_id', $goat->id), 'measured_on')->orderByDesc('measured_on')->orderByDesc('id')->first();
        [$low, $high] = HealthCheckup::NORMAL['temperature_f'];

        // a plain Watch: better weight (and no fever since) answers it, as does a Healthy checkup
        if ($title === 'Checkup: Watch' && $r->severity !== 'high') {
            $c = (clone $checks)->first();
            if ($c && $c->outcome === 'healthy') {
                return "checkup Healthy on {$c->checked_on->format('d/m')} by {$c->examiner}";
            }
            $then = Checkups::lastWeight($goat, $r->occurred_on->copy()->addDay()->toDateString()); // the weight on the day of the watch
            $w = $latestWeight();
            $t = (clone $checks)->whereNotNull('temperature_f')->first();
            if (! $then || ! $w || (float) $w->weight_kg <= $then['kg'] || ($t && ($t->temperature_f < $low || $t->temperature_f > $high))) {
                return null;
            }

            return "weight up to {$w->weight_kg} KG on {$w->measured_on->format('d/m')} (was {$then['kg']} KG at the watch)".($t ? ", temperature normal ({$t->temperature_f} °F)" : '');
        }

        $needsWeight = str_contains($title, 'Weight down');
        $needsTemp = str_contains($title, 'Temperature');
        $needsHealthy = $title === 'Checkup: Sick' || $r->severity === 'high';

        if ($needsWeight) {
            $before = Checkups::lastWeight($goat, $r->occurred_on->toDateString()); // the weight before the drop
            $w = $latestWeight();
            if (! $before || ! $w || (float) $w->weight_kg < $before['kg'] * (1 - self::TOLERANCE_PCT / 100)) {
                return null;
            }
            $reasons[] = "weight back to {$w->weight_kg} KG on {$w->measured_on->format('d/m')} (was {$before['kg']} KG before the drop)";
        }
        if ($needsTemp) {
            $c = (clone $checks)->whereNotNull('temperature_f')->first();
            if (! $c || $c->temperature_f < $low || $c->temperature_f > $high) {
                return null;
            }
            $reasons[] = "temperature normal ({$c->temperature_f} °F on {$c->checked_on->format('d/m')})";
        }
        if ($needsHealthy) {
            $c = (clone $checks)->first();
            if (! $c || $c->outcome !== 'healthy') {
                return null;
            }
            $reasons[] = "checkup Healthy on {$c->checked_on->format('d/m')} by {$c->examiner}";
        }

        return $reasons ? implode('; ', $reasons) : null;
    }

    /** Open watches raised by measurements (the ones this rule can answer). */
    private static function openWatches(Animal $goat)
    {
        return HealthRecord::where('animal_id', $goat->id)->where('kind', 'observation')->where('status', 'open')
            ->where(fn ($q) => $q->where('title', 'like', 'Checkup: %')->orWhere('title', 'Poor growth: veterinary review'))
            ->whereNotIn('id', HealthCase::whereNotNull('report_id')->select('report_id'))
            ->orderBy('occurred_on')->orderBy('id')->get();
    }

    /**
     * D-251: how a goat on Watch is doing since the watch began: improving (weight up since then, no fever since),
     * not improving (weight the same or down, or a fever since), or no recheck yet (nothing measured since).
     *
     * @return array{state: string, since: string, title: string, then: ?float, now: ?float, nowOn: ?string, goal: ?float, temp: ?float}|null
     */
    public static function progress(Animal $goat): ?array
    {
        $watches = self::openWatches($goat);
        if ($watches->isEmpty()) {
            return null;
        }
        $first = $watches->first();
        $then = Checkups::lastWeight($goat, $first->occurred_on->copy()->addDay()->toDateString());
        $w = WeightRecord::where('animal_id', $goat->id)->where(fn ($q) => $q->whereDate('measured_on', '>', $first->occurred_on)->orWhere(fn ($x) => $x->whereDate('measured_on', $first->occurred_on)->where('created_at', '>', $first->created_at)))
            ->orderByDesc('measured_on')->orderByDesc('id')->first();
        $c = HealthCheckup::where('animal_id', $goat->id)->whereNotNull('temperature_f')->where(fn ($q) => $q->whereDate('checked_on', '>', $first->occurred_on)->orWhere(fn ($x) => $x->whereDate('checked_on', $first->occurred_on)->where('created_at', '>', $first->created_at)))
            ->orderByDesc('checked_on')->orderByDesc('id')->first();
        [$low, $high] = HealthCheckup::NORMAL['temperature_f'];
        $fever = $c && ($c->temperature_f < $low || $c->temperature_f > $high);
        $drop = $watches->first(fn ($r) => str_contains($r->title, 'Weight down'));
        $before = $drop ? Checkups::lastWeight($goat, $drop->occurred_on->toDateString()) : null;
        $state = match (true) {
            ! $w && ! $c => 'no_recheck',
            $fever => 'not_improving',
            $w && $then && (float) $w->weight_kg > $then['kg'] => 'improving',
            ! $w => 'no_recheck', // a normal temperature but no weight yet
            default => 'not_improving',
        };

        return [
            'state' => $state, 'since' => $first->occurred_on->format('d/m'), 'title' => preg_replace('/^Checkup: /', '', $first->title),
            'then' => $then['kg'] ?? null, 'now' => $w ? (float) $w->weight_kg : null, 'nowOn' => $w?->measured_on->format('d/m'),
            'goal' => $before ? round($before['kg'] * (1 - self::TOLERANCE_PCT / 100), 1) : null, 'temp' => $c?->temperature_f,
        ];
    }

    /** D-251: every goat's progress (for the herd filters), and the goats taken off Watch in the last 14 days. */
    public static function herd(): array
    {
        return Tenant::memo('recovery.herd', function () {
            $ids = HealthRecord::where('kind', 'observation')->where('status', 'open')
                ->where(fn ($q) => $q->where('title', 'like', 'Checkup: %')->orWhere('title', 'Poor growth: veterinary review'))->distinct()->pluck('animal_id');
            $state = Animal::whereIn('id', $ids)->get()->mapWithKeys(fn (Animal $a) => [$a->id => self::progress($a)['state'] ?? null])->filter()->all();
            $recovered = \App\Models\AnimalEvent::where('summary', 'like', 'Out of Watch:%')->where('occurred_at', '>=', now()->subDays(14))->distinct()->pluck('animal_id')->flip()->all();

            return ['state' => $state, 'recovered' => $recovered];
        });
    }
}
