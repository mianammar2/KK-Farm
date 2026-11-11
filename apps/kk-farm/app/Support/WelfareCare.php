<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\HealthCase;
use App\Models\Task;
use App\Models\WelfareAction;
use App\Models\WelfareCase;
use Illuminate\Validation\ValidationException;

/**
 * D-199: journey J Welfare-to-Treatment (V3 flow 33). Welfare → Veterinary → Housing → Operations: veterinary
 * diagnosis and humane handling take precedence over routine tasks. A goat with a serious open welfare case (high or
 * critical, referred to the vet or escalated) is not handled for routine work (weighing, moving, mating, sale, a
 * finishing batch) and its routine tasks are held, until the vet clears handling or the case closes. The vet's health
 * case is linked as the diagnosis; when it resolves, the welfare case moves to follow-up; it cannot close while the
 * health case is active.
 */
final class WelfareCare
{
    public const SERIOUS = ['high', 'critical'];

    public static function restricts(WelfareCase $c): bool
    {
        return $c->status !== 'closed' && $c->animal_id && $c->handling_cleared_at === null
            && (in_array($c->severity, self::SERIOUS, true) || in_array($c->status, ['vet_referral', 'escalated'], true));
    }

    public static function restriction(string $animalId): ?WelfareCase
    {
        return WelfareCase::where('animal_id', $animalId)->where('status', '!=', 'closed')->whereNull('handling_cleared_at')->orderByDesc('id')->get()
            ->first(fn (WelfareCase $c) => self::restricts($c));
    }

    /** Refuse routine handling while a welfare case holds the goat. */
    public static function assertRoutineAllowed(Animal $a, string $field = 'animal_id'): void
    {
        if ($c = self::restriction($a->id)) {
            throw ValidationException::withMessages([$field => "{$a->farm_number} is under welfare case {$c->label()} (".WelfareCase::ISSUES[$c->issue].'): humane handling first. Only care and vet work until the vet clears routine handling.']);
        }
    }

    /** Hold the goat's open routine tasks (anything but health care) while the case restricts handling. */
    public static function holdTasks(WelfareCase $c): int
    {
        if (! self::restricts($c)) {
            return 0;
        }

        return Task::where('animal_id', $c->animal_id)->whereIn('status', ['open', 'in_progress'])->where('type', '!=', 'health')->whereNull('welfare_hold_case_id')
            ->update(['status' => 'blocked', 'welfare_hold_case_id' => $c->id]);
    }

    public static function releaseTasks(WelfareCase $c): void
    {
        Task::where('welfare_hold_case_id', $c->id)->where('status', 'blocked')->update(['status' => 'open', 'welfare_hold_case_id' => null]);
    }

    /** The vet opened a health case for a goat with an open welfare case: that is its diagnosis. */
    public static function linkHealthCase(HealthCase $h): void
    {
        $c = $h->condition === Intake::CONDITION ? null : WelfareCase::where("animal_id", $h->animal_id)->where("status", "!=", "closed")->whereNull("health_case_id")->orderByDesc("id")->first();
        if (! $c) {
            return;
        }
        $c->update(['health_case_id' => $h->id, 'status' => 'vet_referral']);
        WelfareAction::create(['case_id' => $c->id, 'kind' => 'vet_update', 'notes' => mb_substr("Vet diagnosis {$h->label()}: {$h->condition}".($h->diagnosis ? " · {$h->diagnosis}" : ''), 0, 1000),
            'done_by' => $h->vet ?? $h->recorded_by, 'done_on' => now()->toDateString()]);
        self::holdTasks($c->fresh());
    }

    /** The health case resolved: the welfare case goes to follow-up to confirm the welfare improvement. */
    public static function healthResolved(HealthCase $h): void
    {
        foreach (WelfareCase::where('health_case_id', $h->id)->where('status', '!=', 'closed')->get() as $c) {
            $c->update(['status' => 'follow_up']);
            WelfareAction::create(['case_id' => $c->id, 'kind' => 'vet_update', 'notes' => "Vet case {$h->label()} ".HealthCase::STATUSES[$h->status].'. Confirm the welfare improvement.',
                'done_by' => $h->cleared_by ?? $h->vet ?? 'Vet', 'done_on' => now()->toDateString()]);
        }
    }

    /** A closed case of the same problem on the same goat in the last 90 days. */
    public static function recurrenceOf(?string $animalId, string $issue): ?int
    {
        return $animalId ? WelfareCase::where('animal_id', $animalId)->where('issue', $issue)->where('status', 'closed')->where('closed_at', '>=', now()->subDays(90))->orderByDesc('closed_at')->value('id') : null;
    }

    /** @return array{recurrence: ?float, lameness: int, awaitingVet: int, held: int} */
    public static function kpis(): array
    {
        $recent = WelfareCase::where('opened_on', '>=', now()->subDays(90)->toDateString())->get();

        return [
            'recurrence' => $recent->count() ? round($recent->whereNotNull('recurrence_of_id')->count() / $recent->count() * 100, 1) : null,
            'lameness' => WelfareCase::where('issue', 'lameness')->where('opened_on', '>=', now()->subDays(30)->toDateString())->count(),
            'awaitingVet' => WelfareCase::where('status', 'vet_referral')->whereNull('health_case_id')->count(),
            'held' => Task::whereNotNull('welfare_hold_case_id')->where('status', 'blocked')->count(),
        ];
    }
}
