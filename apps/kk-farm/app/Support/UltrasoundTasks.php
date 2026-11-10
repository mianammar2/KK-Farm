<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\Department;
use App\Models\Mating;
use App\Models\PregnancyCheck;
use App\Models\Task;

/** D-166: the work an ultrasound result asks for, as tasks. The vet's finding is recorded as given. */
final class UltrasoundTasks
{
    public static function raise(Mating $m, Animal $dam, string $status, ?int $fetuses, ?string $next): void
    {
        $vet = Department::where('name', 'Veterinary')->value('id');
        $care = Department::where('name', 'Caretaking')->value('id') ?? $vet;
        $feed = Department::where('name', 'Feeding')->value('id') ?? $care;
        $task = fn (string $title, string $type, string $priority, ?int $dept, string $notes, $due = null) => Task::create([
            'title' => $title, 'type' => $type, 'priority' => $priority, 'status' => 'open', 'due_on' => $due ?? now()->toDateString(),
            'animal_id' => $dam->id, 'pen_id' => $dam->pen_id, 'department_id' => $dept, 'notes' => $notes,
        ]);
        $n = $dam->farm_number;

        match (true) {
            in_array($status, PregnancyCheck::REPEAT, true) => $task("Repeat ultrasound: {$n}", 'pregnancy_check', 'medium', $vet,
                PregnancyCheck::STATUSES[$status].'. Repeat the examination'.($status === 'FHN' ? ' and confirm the fetal heartbeat.' : '.'), $next),
            $status === 'NPG' => $task("Breeding reassessment: {$n}", 'breeding', 'medium', $care,
                'Not pregnant on ultrasound. Watch for heat and decide with the vet when to mate her again.'),
            $status === 'SFL' => $task("Vet alert: suspected fetal loss – {$n}", 'health', 'high', $vet,
                'Possible embryonic or fetal death. The pregnancy stays open until the vet confirms; reassess soon.', $next ?? now()->addDays(3)->toDateString()),
            $status === 'HYD' => $task("Vet investigation: hydrometra – {$n}", 'health', 'high', $vet,
                'Fluid-filled uterus without a true pregnancy (pseudopregnancy). The vet investigates and plans treatment.'),
            $status === 'ABN' => $task("Vet investigation: abnormal ultrasound – {$n}", 'health', 'high', $vet,
                'An abnormal reproductive finding on ultrasound. The vet investigates.'),
            default => null,
        };
        if (in_array($status, ['PCF', 'VPG', 'MPG'], true) && ($status === 'MPG' || ($fetuses ?? 0) >= 2)) {
            $task("{$n}: ".PregnancyCheck::fetalLabel($fetuses ?? 2).' – nutrition and monitoring', 'feeding', 'high', $feed,
                'More than one fetus. Ask the vet or nutritionist about the late-pregnancy ration (energy and protein) and watch her body condition; twins and triplets raise the risk of pregnancy toxaemia.');
        }
    }
}
