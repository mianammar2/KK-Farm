<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\Department;
use App\Models\Kidding;
use App\Models\Task;

/**
 * D-161: what a pregnancy outcome asks of the team. The system records and recommends; the vet decides.
 * - Abortion or early embryonic loss: a vet review (high) and a biosecurity task (critical): isolate the doe, keep the
 *   aborted material for the vet, gloves on (some causes of goat abortion spread to people).
 * - Retained placenta, a complication, veterinary treatment, a Cesarean or a mother who died: a vet review.
 * - Weak kids: an observation task for the caretakers to watch them feed in the first 24 hours.
 */
class PregnancyAlerts
{
    /** @param  array<int, string>  $kidTags */
    public static function raise(Kidding $k, Animal $dam, array $kidTags = []): void
    {
        $vet = Department::where('name', 'Veterinary')->value('id');
        $care = Department::where('name', 'Caretaking')->value('id') ?? $vet;
        $task = fn (string $title, string $type, string $priority, ?int $dept, string $notes, $due = null) => Task::create([
            'title' => $title, 'type' => $type, 'priority' => $priority, 'status' => 'open', 'due_on' => $due ?? now()->toDateString(),
            'animal_id' => $dam->id, 'pen_id' => $dam->pen_id, 'department_id' => $dept, 'notes' => $notes,
        ]);
        $what = Kidding::OUTCOMES[$k->outcome] ?? 'Pregnancy outcome';

        if (in_array($k->outcome, Kidding::LOSSES, true)) {
            $task("Vet review: {$what} – {$dam->farm_number}", 'health', 'high', $vet,
                'Pregnancy lost on '.$k->kidded_on->format('j M Y').($k->abortion_cause ? " · suspected cause: {$k->abortion_cause}" : '').'. The vet investigates the cause and records the diagnosis.', $k->follow_up_on);
            $task("Biosecurity: isolate {$dam->farm_number} after {$what}", 'biosecurity', 'critical', $care,
                'Move her away from the other does, keep the aborted material and bedding for the vet, wear gloves and clean the area. Some causes of goat abortion can spread to people.');

            return;
        }
        if (in_array($k->mother_status, Kidding::VET_REVIEW, true) || $k->delivery_method === 'CSE') {
            $why = $k->delivery_method === 'CSE' && ! in_array($k->mother_status, Kidding::VET_REVIEW, true) ? 'after a Cesarean' : (Kidding::MOTHER[$k->mother_status] ?? '');
            $task("Vet review: {$dam->farm_number} – {$why}", 'health', $k->mother_status === 'DED' ? 'critical' : 'high', $vet,
                'After kidding on '.$k->kidded_on->format('j M Y').($k->complications ? " · {$k->complications}" : '').'.'.($k->mother_status === 'DED' ? ' Record her death under Record Exit and make sure the kids get colostrum.' : ''), $k->follow_up_on);
        }
        if ($k->weak) {
            $task("Watch weak kid".($k->weak > 1 ? 's' : '')." of {$dam->farm_number}", 'observation', 'high', $care,
                'Born alive but weak'.($kidTags ? ' ('.implode(', ', $kidTags).')' : '').'. Check they suckle colostrum within the first hours and keep them warm. If one dies, record its death; its birth stays a live birth.');
        }
    }
}
