<?php

namespace App\Support;

use App\Models\AnimalMovement;
use App\Models\DeathReport;
use App\Models\HealthRecord;

/**
 * D-326: the dashboard's Mortality section: every goat that died or was slaughtered (exits Death and Slaughter), and the
 * vet's reports still waiting for the administrator. Each row carries the reason, what was seen, the postmortem, who
 * reported it and who approved it, so a tile or a reason opens the records behind it.
 */
final class MortalitySummary
{
    public const KINDS = ['death' => 'Death', 'slaughter' => 'Slaughter'];

    public static function build(): array
    {
        $exits = AnimalMovement::with(['animal.breed', 'fromPen.shed'])->whereIn('type', array_keys(self::KINDS))->orderByDesc('moved_on')->orderByDesc('id')->get();
        $reports = DeathReport::whereIn('movement_id', $exits->pluck('id'))->get()->keyBy('movement_id');
        $pm = HealthRecord::where('kind', 'postmortem')->whereIn('animal_id', $exits->pluck('animal_id'))->orderByDesc('occurred_on')->get()->unique('animal_id')->keyBy('animal_id');
        $age = fn ($a, $on) => $a?->birth_date ? self::age((int) $a->birth_date->diffInMonths($on)) : null;

        $rows = $exits->map(function (AnimalMovement $m) use ($reports, $pm, $age) {
            $r = $reports[$m->id] ?? null;
            $p = $pm[$m->animal_id] ?? null;
            $reason = $r ? preg_replace('/^Slaughter: /', '', $r->cause) : ($p?->title ?? preg_replace('/^(Died|Slaughtered) [^:]*: /', '', (string) $m->reason));

            return [
                'id' => 'm'.$m->id, 'goatId' => $m->animal_id, 'number' => $m->animal?->farm_number, 'breed' => $m->animal?->breed?->name, 'sex' => $m->animal?->sex ? ucfirst($m->animal->sex) : null,
                'age' => $age($m->animal, $m->moved_on), 'pen' => $m->fromPen?->fullName(), 'kind' => self::KINDS[$m->type], 'on' => $m->moved_on->format('d/m/Y'), 'sort' => $m->moved_on->format('Y-m-d'),
                'reason' => $reason ?: 'Reason not recorded', 'seen' => $r?->circumstances ?? $m->notes, 'postmortem' => $r?->postmortem ?? ($p ? $p->notes : null),
                'reportedBy' => $r?->reported_by ?? $m->recorded_by, 'approvedBy' => $r?->decided_by ?? $m->authorized_by, 'status' => 'Recorded',
            ];
        });
        $waiting = DeathReport::with(['animal.breed', 'animal.pen.shed'])->where('status', 'pending')->orderByDesc('died_at')->get()->map(fn (DeathReport $r) => [
            'id' => 'r'.$r->id, 'goatId' => $r->animal_id, 'number' => $r->animal?->farm_number, 'breed' => $r->animal?->breed?->name, 'sex' => $r->animal?->sex ? ucfirst($r->animal->sex) : null,
            'age' => $age($r->animal, $r->died_at), 'pen' => $r->animal?->pen?->fullName(), 'kind' => self::KINDS[$r->kind ?? 'death'] ?? 'Death', 'on' => $r->died_at->format('d/m/Y'), 'sort' => $r->died_at->format('Y-m-d'),
            'reason' => preg_replace('/^Slaughter: /', '', $r->cause), 'seen' => $r->circumstances, 'postmortem' => $r->postmortem,
            'reportedBy' => $r->reported_by, 'approvedBy' => null, 'status' => 'Waiting for approval',
        ]);
        $all = $waiting->concat($rows)->sortByDesc('sort')->values();
        $recorded = $rows;

        return [
            'deaths' => $recorded->where('kind', 'Death')->count(),
            'slaughtered' => $recorded->where('kind', 'Slaughter')->count(),
            'waiting' => $waiting->count(),
            'thisYear' => $recorded->filter(fn ($r) => str_starts_with($r['sort'], Feed::now()->format('Y')))->count(),
            // reasons, most frequent first (recorded exits only)
            'byReason' => $recorded->groupBy(fn ($r) => $r['kind'].'|'.$r['reason'])->map(fn ($g, $k) => ['kind' => explode('|', $k, 2)[0], 'reason' => explode('|', $k, 2)[1], 'count' => $g->count()])
                ->sortByDesc('count')->values()->all(),
            'rows' => $all->map(fn ($r) => collect($r)->except('sort')->all())->all(),
        ];
    }

    private static function age(int $months): string
    {
        return $months < 12 ? "$months months" : intdiv($months, 12).' y '.($months % 12).' m';
    }
}
