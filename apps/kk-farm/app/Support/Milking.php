<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\Kidding;
use App\Models\Lactation;
use App\Models\MilkRecord;
use App\Models\Treatment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * D-117: lactations and milk. Kidding starts a lactation by itself, so the doe becomes a milking doe: her feeding group
 * (Lactating Does) and nutrition standard (Lactating, or High-Producing once her 7-day average reaches the farm's line)
 * follow without anyone changing her. Drying off ends it and she goes back to the adult doe standard.
 */
class Milking
{
    /** Start a lactation (closing one still open, e.g. she kidded again while in milk). */
    public static function start(Animal $doe, CarbonInterface $on, string $source, string $by, ?Kidding $kidding = null): Lactation
    {
        $open = Lactation::where('animal_id', $doe->id)->open()->first();
        if ($open) {
            if ($kidding && $open->kidding_id === $kidding->id) {
                return $open;
            }
            $open->update(['dried_on' => $on->toDateString(), 'dry_reason' => 'Kidded again', 'dried_by' => $by]);
        }
        $lactation = Lactation::create([
            'animal_id' => $doe->id, 'kidding_id' => $kidding?->id, 'number' => Lactation::where('animal_id', $doe->id)->count() + 1,
            'started_on' => $on->toDateString(), 'source' => $source, 'recorded_by' => $by,
        ]);
        $doe->update(['repro_status' => 'lactating', 'repro_state' => 'lactating']);
        Farm::event($doe, 'breeding', "In milk: lactation {$lactation->number} from ".$on->format('j M Y').($source === 'arrival' ? ' (in milk when she came to the farm)' : ' (kidded)'), $by, $on);
        Tenant::flush();

        return $lactation;
    }

    public static function dryOff(Lactation $lactation, CarbonInterface $on, string $reason, string $by): void
    {
        if ($on->lt($lactation->started_on)) {
            throw ValidationException::withMessages(['dried_on' => 'Drying off cannot be before the lactation started ('.$lactation->started_on->format('j M Y').').']);
        }
        $lactation->update(['dried_on' => $on->toDateString(), 'dry_reason' => $reason, 'dried_by' => $by]);
        $doe = $lactation->animal;
        $total = MilkRecord::current()->where('lactation_id', $lactation->id)->sum('litres');
        // Pregnant again stays pregnant; otherwise she is a dry doe.
        if (! in_array($doe->repro_status, ['pregnant', 'expected_kidding'], true)) {
            $doe->update(['repro_status' => 'dry', 'repro_state' => null]);
        }
        Farm::event($doe, 'breeding', "Dried off: lactation {$lactation->number} ended after {$lactation->daysInMilk()} days, ".round($total, 1)." L in total ({$reason})", $by, $on);
        Tenant::flush();
    }

    /** The milk withdrawal in force for a doe on a day, from her treatments (never a stored flag). */
    public static function withdrawalOn(string $animalId, CarbonInterface $day): ?Carbon
    {
        $clear = Treatment::where('animal_id', $animalId)->whereNotNull('milk_clear_on')->where('given_on', '<=', $day->toDateString())->max('milk_clear_on');

        return $clear && Carbon::parse($clear)->gt($day) ? Carbon::parse($clear) : null;
    }

    /**
     * Record one session for several does. Each row carries a client id, so sending it twice saves it once. A doe
     * already recorded for that day and session gets a correction that supersedes the earlier figure.
     *
     * @param  array<int, array{id: string, animal_id: string, litres: float|string}>  $rows
     * @return array{saved: int, corrected: int, discarded: int}
     */
    public static function record(CarbonInterface $on, string $session, array $rows, string $by): array
    {
        $out = ['saved' => 0, 'corrected' => 0, 'discarded' => 0];
        $open = Lactation::open()->whereIn('animal_id', collect($rows)->pluck('animal_id'))->get()->keyBy('animal_id');
        foreach ($rows as $i => $row) {
            if (MilkRecord::whereKey($row['id'])->exists()) {
                continue;
            }
            $lactation = $open[$row['animal_id']] ?? null;
            if (! $lactation || $on->lt($lactation->started_on)) {
                throw ValidationException::withMessages(["rows.$i.litres" => 'This doe is not in milk on '.$on->format('j M Y').'.']);
            }
            $previous = MilkRecord::current()->where('animal_id', $row['animal_id'])->whereDate('milked_on', $on->toDateString())->where('session', $session)->first();
            $withdrawal = self::withdrawalOn($row['animal_id'], $on);
            MilkRecord::create([
                'id' => $row['id'], 'animal_id' => $row['animal_id'], 'lactation_id' => $lactation->id, 'milked_on' => $on->toDateString(), 'session' => $session,
                'litres' => $row['litres'], 'discarded' => $withdrawal !== null, 'discard_reason' => $withdrawal ? 'Milk withdrawal until '.$withdrawal->format('j M Y') : null,
                'supersedes_id' => $previous?->id, 'recorded_by' => $by,
            ]);
            $out[$previous ? 'corrected' : 'saved']++;
            $out['discarded'] += $withdrawal ? 1 : 0;
        }
        Tenant::flush();

        return $out;
    }

    /** @return Collection<string, float> each doe's average litres a day over the last 7 days she was milked */
    public static function weekAverages(): Collection
    {
        return Tenant::memo('milk.week', function () {
            $from = now()->subDays(6)->toDateString();
            $open = Lactation::open()->pluck('id');

            return MilkRecord::current()->whereIn('lactation_id', $open)->where('milked_on', '>=', $from)->get()
                ->groupBy('animal_id')->map(fn ($rs) => round($rs->sum('litres') / max(1, $rs->pluck('milked_on')->map->toDateString()->unique()->count()), 2));
        });
    }

    /** @return Collection<string, Lactation> open lactations by doe */
    public static function openByDoe(): Collection
    {
        return Tenant::memo('milk.open', fn () => Lactation::open()->get()->keyBy('animal_id'));
    }

    /** A doe in milk whose 7-day average reaches the farm's high-yield line. */
    public static function isHighYield(Animal $doe): bool
    {
        $line = Tenant::org()?->high_yield_litres;

        return $line !== null && (self::weekAverages()[$doe->id] ?? 0) >= (float) $line;
    }
}
