<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\CareProtocol;
use App\Models\HealthRecord;
use Illuminate\Support\Carbon;

/**
 * D-173: the vaccination and deworming calendar, worked out from the vet's protocols and the care already given:
 * a goat is due interval days after its last dose of that protocol, or, never given, once it reaches the first age
 * (or now when the protocol has no first age). Goats due within the next 30 days, overdue first.
 */
final class CareCalendar
{
    public const HORIZON_DAYS = 30;

    /** @return list<array<string, mixed>> one row per protocol with its due goats */
    public static function due(): array
    {
        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays(self::HORIZON_DAYS);
        $goats = Animal::active()->with('breed')->get();
        $pregnant = HerdStats::pregnantDams()['pregnant'];
        $out = [];
        foreach (CareProtocol::with('medicine')->where('active', true)->orderBy('kind')->orderBy('name')->get() as $p) {
            $stage = str_starts_with((string) $p->applies_to, 'stage:') ? substr($p->applies_to, 6) : null; // D-228
            $scope = $goats->filter(fn (Animal $a) => $stage !== null ? ($a->birth_date && AgeStages::codeFor($a->birth_date) === $stage) : match ($p->applies_to) {
                'kids' => $a->effectiveAge() === 'kid', 'growers' => $a->effectiveAge() === 'grower', 'adults' => $a->effectiveAge() === 'adult',
                'does' => $a->sex === 'female', 'bucks' => $a->sex === 'male', 'pregnant' => isset($pregnant[$a->id]), default => true,
            });
            $last = HealthRecord::where('care_status', 'administered')->where(fn ($q) => $q->where('protocol_id', $p->id)->orWhere(fn ($q) => $q->whereNull('protocol_id')->where('kind', $p->kind)->where('title', 'ilike', $p->name)))
                ->whereIn('animal_id', $scope->modelKeys())->selectRaw('animal_id, max(occurred_on) as last_on')->groupBy('animal_id')->pluck('last_on', 'animal_id');
            $rows = [];
            foreach ($scope as $a) {
                $lastOn = isset($last[$a->id]) ? Carbon::parse($last[$a->id]) : null;
                if ($lastOn && ! $p->interval_days) {
                    continue; // a one-time protocol already given
                }
                $dueOn = $lastOn ? $lastOn->copy()->addDays($p->interval_days)
                    : ($p->first_age_days && $a->birth_date ? $a->birth_date->copy()->addDays($p->first_age_days) : $today->copy());
                if ($dueOn->greaterThan($horizon)) {
                    continue;
                }
                $rows[] = [
                    'id' => $a->id, 'number' => $a->farm_number, 'sex' => $a->sex, 'stage' => $a->effectiveAge(), 'code' => $a->classCode(), 'breed' => $a->breed?->name, 'pen' => $a->pen?->fullName(),
                    'due' => $dueOn->toDateString(), 'dueLabel' => $dueOn->format('d/m'), 'overdue' => $dueOn->lessThan($today), 'last' => $lastOn?->format('d/m/Y'),
                ];
            }
            usort($rows, fn ($x, $y) => strcmp($x['due'], $y['due']));
            $out[] = [
                'id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'appliesTo' => CareProtocol::applies()[$p->applies_to] ?? $p->applies_to,
                'interval' => $p->interval_days, 'medicineId' => $p->medicine_id, 'medicine' => $p->medicine?->name, 'dose' => $p->dose_per_goat,
                'goats' => $rows, 'overdue' => count(array_filter($rows, fn ($r) => $r['overdue'])),
            ];
        }

        return $out;
    }
}
