<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\Department;
use App\Models\HealthCase;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * D-172: purchased and transferred goats go through Intake → Registration → RFID → Quarantine → Veterinary Clearance
 * before they join the herd. Registering one opens an isolated "Intake quarantine" health case (so she shows as
 * Quarantine everywhere and the Biosecurity rules apply) and a clearance task for the vet, due when the farm's
 * quarantine days are over. The vet clears her on the Intake & Quarantine page; the case closes and she is healthy.
 */
final class Intake
{
    public const CONDITION = 'Intake quarantine';

    public static function days(): int
    {
        return (int) (Tenant::org()?->quarantine_days ?: 14);
    }

    public static function open(Animal $animal, User $by): HealthCase
    {
        return DB::transaction(fn () => self::openNow($animal, $by));
    }

    private static function openNow(Animal $animal, User $by): HealthCase
    {
        DB::statement('LOCK TABLE health_cases IN SHARE ROW EXCLUSIVE MODE');
        $days = self::days();
        $case = HealthCase::create([
            'case_no' => (int) HealthCase::max('case_no') + 1, 'animal_id' => $animal->id, 'onset_on' => now()->toDateString(), 'condition' => self::CONDITION,
            'symptoms' => "New arrival ({$animal->origin}). Kept apart for {$days} days until the vet clears her for the herd.",
            'severity' => 'low', 'status' => 'monitoring', 'isolated' => true, 'vet' => $by->name, 'recorded_by' => $by->name,
        ]);
        Checkups::syncHealth($animal);
        Task::create([
            'title' => "Intake clearance: {$animal->farm_number}", 'type' => 'biosecurity', 'priority' => 'high', 'status' => 'open',
            'due_on' => now()->addDays($days)->toDateString(), 'animal_id' => $animal->id, 'pen_id' => $animal->pen_id,
            'department_id' => Department::where('name', 'Veterinary')->value('id'),
            'notes' => "Quarantine of {$days} days after arrival. Check health, deworm and vaccinate as the vet decides, confirm the RFID tag, then clear her on Intake & Quarantine.",
        ]);
        Farm::event($animal, 'health', "Intake quarantine started ({$days} days) until veterinary clearance", $by->name);

        return $case;
    }

    /** @return array<int, string> ids of goats in intake quarantine right now */
    public static function quarantinedIds(): array
    {
        return HealthCase::where('condition', self::CONDITION)->whereIn('status', HealthCase::ACTIVE)->pluck('animal_id')->all();
    }

    /**
     * D-190 (journey A key control): no direct mixing before clearance. A goat in intake quarantine shares a pen only
     * with other goats in intake quarantine, and a cleared goat never joins a pen that holds quarantined goats.
     */
    public static function assertNoMixing(Animal $animal, \App\Models\Pen $to): void
    {
        $quarantined = self::quarantinedIds();
        $others = Animal::active()->where('pen_id', $to->id)->where('id', '!=', $animal->id)->pluck('id')->all();
        $inQ = in_array($animal->id, $quarantined, true);
        $mixed = $inQ ? array_diff($others, $quarantined) : array_intersect($others, $quarantined);
        if ($mixed) {
            throw \Illuminate\Validation\ValidationException::withMessages(['to_pen_id' => $inQ
                ? "{$animal->farm_number} is in intake quarantine. It cannot join {$to->fullName()} with herd goats before the vet clears it."
                : "{$to->fullName()} holds goats in intake quarantine. Herd goats cannot be mixed with them before clearance."]);
        }
    }

    /** Extend quarantine (clearance not granted yet): the clearance falls due later and the reason is kept. */
    public static function extend(HealthCase $case, int $days, string $reason, User $by): void
    {
        DB::transaction(function () use ($case, $days, $reason, $by) {
            $animal = $case->animal;
            $task = Task::where('animal_id', $animal->id)->where('title', "Intake clearance: {$animal->farm_number}")->whereIn('status', Task::OPEN)->first();
            $due = now()->addDays($days)->toDateString();
            $task ? $task->update(['due_on' => $due]) : null;
            $case->update(['notes' => trim(($case->notes ? "{$case->notes}\n" : '')."Quarantine extended {$days} days (".now()->format('j M')."): {$reason}")]);
            Farm::event($animal, 'health', "Intake quarantine extended by {$days} days: {$reason}", $by->name);
        });
    }

    /** The vet's clearance: the quarantine case closes, she is healthy and joins the herd. */
    public static function clear(HealthCase $case, User $by, ?string $notes = null): void
    {
        DB::transaction(function () use ($case, $by, $notes) {
            $case->update([
                'status' => 'closed', 'resolved_on' => now()->toDateString(), 'vet' => $by->name,
                'diagnosis' => 'Cleared for the herd after intake quarantine', 'notes' => trim(($case->notes ? "{$case->notes}\n" : '').($notes ?? '')) ?: null,
            ]);
            $animal = $case->animal;
            Checkups::syncHealth($animal);
            Task::where('animal_id', $animal->id)->where('title', "Intake clearance: {$animal->farm_number}")->whereIn('status', Task::OPEN)
                ->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $by->name]);
            Farm::event($animal, 'health', 'Veterinary clearance after intake quarantine'.($notes ? ": {$notes}" : ''), $by->name);
        });
    }

    /** @return Collection<int, array<string, mixed>> goats in intake quarantine, with their checklist */
    public static function board(): Collection
    {
        $days = self::days();

        return HealthCase::with(['animal.breed', 'animal.pen.shed'])->where('condition', self::CONDITION)->whereIn('status', HealthCase::ACTIVE)->orderBy('onset_on')->get()
            ->filter(fn (HealthCase $c) => $c->animal && $c->animal->status === 'active')
            ->map(function (HealthCase $c) use ($days) {
                $a = $c->animal;
                $day = (int) $c->onset_on->diffInDays(now()->startOfDay());

                return [
                    'caseId' => $c->id, 'goatId' => $a->id, 'number' => $a->farm_number, 'name' => $a->name, 'sex' => $a->sex, 'coat' => $a->coat, 'breed' => $a->breed?->name,
                    'stage' => $a->effectiveAge(), 'origin' => $a->origin, 'arrived' => $c->onset_on->format('j M Y'), 'day' => $day, 'days' => $days, 'ready' => $day >= $days,
                    'pen' => $a->pen?->fullName(), 'rfid' => \App\Models\RfidAssignment::where('animal_id', $a->id)->whereNull('valid_to')->exists(),
                    'weighed' => $a->weights()->exists(), 'checked' => \App\Models\HealthCheckup::where('animal_id', $a->id)->where('checked_on', '>=', $c->onset_on)->exists(),
                ];
            })->values();
    }
}
