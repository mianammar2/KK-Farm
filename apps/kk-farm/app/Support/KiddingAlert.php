<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Mating;
use App\Models\Task;

/**
 * D-103: the kidding alert. Gestation is about 150 days (farm setting); from 10 days before the expected kidding
 * (farm setting) the doe is on alert: a task to prepare the kidding pen and watch her, the Action Center (urgent in
 * the last 3 days, overdue after the date), and her goat page.
 */
final class KiddingAlert
{
    public const PREFIX = 'Prepare for kidding: ';

    public static function sync(Mating $m): void
    {
        $doe = $m->dam()->first();
        if (! $doe) {
            return;
        }
        $title = self::PREFIX.$doe->farm_number;
        $task = Task::where('animal_id', $doe->id)->where('title', 'like', self::PREFIX.'%')->whereIn('status', Task::OPEN)->latest('id')->first();
        if ($m->status !== 'pregnant' || ! $m->expected_kidding_on) {
            // Kidded, not pregnant or closed: the open preparation task is no longer needed.
            $task?->update(['status' => $m->status === 'kidded' ? 'completed' : 'cancelled', 'completed_at' => now(), 'completed_by' => 'system']);

            return;
        }
        $days = (int) (Tenant::org()?->kidding_alert_days ?: 10);
        $due = $m->expected_kidding_on->copy()->subDays($days);
        // D-163: the Maternity Unit's Pre-Kidding Unit, when the farm has one.
        $pku = \App\Models\Pen::with('shed')->where('name', 'PKU-01')->whereHas('shed', fn ($q) => $q->where('type', 'MTU'))->first();
        $notes = "Expected kidding {$m->expected_kidding_on->format('d/m/Y')}.".($pku ? " Move her to {$pku->fullName()} (Pre-Kidding Unit) and a Kidding PEN when labour starts." : ' Prepare a clean kidding PEN.')
            .' Bedding and colostrum ready; watch her closely and call the vet if labour is difficult.';
        if ($task) {
            $task->update(['due_on' => $due, 'notes' => $notes]);
        } else {
            Task::create([
                'title' => $title, 'type' => 'kidding', 'priority' => 'high', 'status' => 'open', 'due_on' => $due, 'animal_id' => $doe->id, 'pen_id' => $doe->pen_id,
                'department_id' => Department::where('name', 'Caretaking')->value('id') ?? Department::where('name', 'Veterinary')->value('id'), 'notes' => $notes,
            ]);
        }
    }
}
