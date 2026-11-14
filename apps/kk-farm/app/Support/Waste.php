<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\Department;
use App\Models\Task;
use App\Models\WasteDisposal;
use App\Models\WasteStream;

/**
 * D-204: journey O Waste-to-Safe-Disposal (V3 flow 38). Environment → Vet → Quality → Finance: the disposal method of
 * each waste route is set by a named, qualified local adviser (the system records it, never decides it); a route
 * without one cannot be used. Biological waste needs protection and disinfection confirmed. A death raises a carcass
 * disposal task. Contractor routes have a pickup interval; a gap longer than it is a missed pickup. Each disposal is
 * audited by someone other than the person who recorded it.
 */
final class Waste
{
    /** A goat died: dispose of the carcass by the approved route. */
    public static function carcassTask(Animal $a): void
    {
        $stream = WasteStream::where('kind', 'carcass')->where('active', true)->whereNotNull('adviser')->first();
        Task::create(['title' => mb_substr("Dispose carcass: {$a->farm_number}", 0, 190), 'type' => 'health', 'priority' => 'high', 'status' => 'open', 'due_on' => now()->toDateString(),
            'animal_id' => $a->id, 'department_id' => Department::where('name', 'Management')->value('id'),
            'notes' => $stream ? 'Use the approved route: '.WasteStream::METHODS[$stream->method].($stream->instructions ? ". {$stream->instructions}" : '') : 'No approved carcass route yet: ask the vet / local adviser before disposal. Wear gloves; do not open the carcass.']);
    }

    /** Gaps longer than the pickup interval in the last 90 days, and whether the route is overdue now. */
    public static function pickups(WasteStream $s): array
    {
        if (! $s->pickup_every_days) {
            return ['missed' => 0, 'overdue' => false, 'last' => null];
        }
        $from = now()->subDays(90)->startOfDay();
        $dates = $s->disposals()->where('disposed_on', '>=', $from->toDateString())->orderBy('disposed_on')->pluck('disposed_on')->map(fn ($d) => \Illuminate\Support\Carbon::parse($d));
        $last = $s->disposals()->max('disposed_on');
        $missed = 0;
        $prev = $dates->first() ?? null;
        foreach ($dates->skip(1) as $d) {
            $missed += max(0, intdiv((int) $prev->diffInDays($d) - 1, $s->pickup_every_days));
            $prev = $d;
        }
        $since = $last ? (int) \Illuminate\Support\Carbon::parse($last)->diffInDays(now()->startOfDay()) : null;
        if ($since !== null) {
            $missed += max(0, intdiv($since - 1, $s->pickup_every_days));
        }

        return ['missed' => $missed, 'overdue' => $since === null || $since > $s->pickup_every_days, 'last' => $last ? \Illuminate\Support\Carbon::parse($last)->format('d/m/Y') : null];
    }

    /** @return array<string, mixed> */
    public static function kpis(): array
    {
        $since = now()->subDays(90)->toDateString();
        $recent = WasteDisposal::where('disposed_on', '>=', $since)->get();
        $streams = WasteStream::where('active', true)->get();
        $deadIds = Animal::where('status', 'deceased')->where('exit_date', '>=', $since)->pluck('id');
        $disposed = WasteDisposal::whereIn('animal_id', $deadIds)->pluck('animal_id')->unique();

        return [
            'missedPickups' => $streams->sum(fn ($s) => self::pickups($s)['missed']),
            'audited' => $recent->count() ? (int) round($recent->whereNotNull('verified_at')->count() / $recent->count() * 100) : null,
            'toAudit' => WasteDisposal::whereNull('verified_at')->count(),
            'carcassesPending' => $deadIds->diff($disposed)->count(),
            'unapproved' => $streams->reject(fn ($s) => $s->approved())->count(),
            'cost90' => (int) round($recent->sum('cost_minor') / 100),
        ];
    }
}
