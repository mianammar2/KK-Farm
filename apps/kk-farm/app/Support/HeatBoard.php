<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\HeatRecord;
use App\Models\Mating;

/**
 * D-175: heat detection board: breeding-age does that are not pregnant, with their last heat, the next expected heat
 * (last + 21 days) and a status: In heat now (seen in the last 2 days: ready for mating), Heat due (expected within
 * 3 days), Watch (later), Not observed yet. Does already mated in the last 21 days wait for their pregnancy check.
 */
final class HeatBoard
{
    public static function build(): array
    {
        $today = now()->startOfDay();
        $pregnant = HerdStats::pregnantDams()['pregnant'];
        $recentlyMated = Mating::whereIn('status', ['open', 'inconclusive'])->where('mated_on', '>=', $today->copy()->subDays(HeatRecord::CYCLE_DAYS))->pluck('dam_id')->flip();
        $last = HeatRecord::selectRaw('dam_id, max(observed_on) as last_on, count(*) as times')->groupBy('dam_id')->get()->keyBy('dam_id');
        $rows = [];
        foreach (Animal::active()->with(['breed', 'pen.shed'])->where('sex', 'female')->get() as $a) {
            if (isset($pregnant[$a->id]) || $a->effectiveAge() === 'kid' || $a->isMonitored()) {
                continue;
            }
            $l = $last[$a->id] ?? null;
            $lastOn = $l ? \Illuminate\Support\Carbon::parse($l->last_on) : null;
            $next = $lastOn?->copy()->addDays(HeatRecord::CYCLE_DAYS);
            $status = match (true) {
                isset($recentlyMated[$a->id]) => 'mated',
                $lastOn && $lastOn->greaterThanOrEqualTo($today->copy()->subDays(2)) => 'in_heat',
                $next && $next->lessThanOrEqualTo($today->copy()->addDays(3)) => 'due',
                $lastOn !== null => 'watch',
                default => 'unknown',
            };
            $rows[] = [
                'id' => $a->id, 'number' => $a->farm_number, 'name' => $a->name, 'breed' => $a->breed?->name, 'stage' => $a->effectiveAge(), 'pen' => $a->pen?->fullName(),
                'last' => $lastOn?->format('d/m'), 'next' => $next?->format('d/m'), 'nextIso' => $next?->toDateString(), 'times' => (int) ($l->times ?? 0), 'status' => $status,
            ];
        }
        $order = ['in_heat' => 0, 'due' => 1, 'watch' => 2, 'unknown' => 3, 'mated' => 4];
        usort($rows, fn ($x, $y) => [$order[$x['status']], $x['nextIso'] ?? '9', $x['number']] <=> [$order[$y['status']], $y['nextIso'] ?? '9', $y['number']]);

        return $rows;
    }
}
