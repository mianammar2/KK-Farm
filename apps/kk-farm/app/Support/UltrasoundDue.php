<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Mating;
use App\Models\Task;

/**
 * D-298: the vet's ultrasound after a mating. When a mating is recorded the vet gets a task due on the pregnancy-check day
 * (the farm's setting, e.g. 35 days after mating) that opens Breeding → Ultrasound; recording the ultrasound there closes it.
 */
final class UltrasoundDue
{
    public const TITLE = 'Ultrasound: ';

    public static function task(Mating $m): void
    {
        $dam = $m->dam;
        if (! $dam || ! $m->check_due_on) {
            return;
        }
        $days = (int) $m->mated_on->diffInDays($m->check_due_on);
        Task::create([
            'title' => mb_substr(self::TITLE.$dam->farm_number." (day {$days} after mating)", 0, 190), 'type' => 'pregnancy_check', 'priority' => 'medium', 'status' => 'open',
            'due_on' => $m->check_due_on->toDateString(), 'animal_id' => $dam->id, 'pen_id' => $dam->pen_id,
            'department_id' => Department::where('name', 'Veterinary')->value('id'),
            'notes' => "Mated {$m->mated_on->format('d/m/Y')}. Do the ultrasound on Breeding → Ultrasound.",
        ]);
    }

    public static function close(Mating $m, string $by): void
    {
        Task::where('animal_id', $m->dam_id)->where('type', 'pregnancy_check')->where('title', 'like', self::TITLE.'%')->whereIn('status', Task::OPEN)
            ->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $by]);
    }
}
