<?php

namespace App\Support;

use App\Models\Department;
use App\Models\MilkLot;
use App\Models\QualityCase;
use App\Models\QualityHold;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * D-189/D-196: opening a quality case and containing it, shared by the Quality page and the automatic triggers of
 * journey G (a flagged doe, a temperature excursion, a positive residue test, a customer complaint).
 */
final class QualityCases
{
    /**
     * Open a case. $data: source, title, description?, subject, severity, animal_id?, milk_lot_id?, feed_item_id?,
     * medicine_id?, batch?, process?, incident_id?, immediate_risk, containment?. $lots holds extra milk lots on the case.
     */
    public static function open(array $data, User $by, iterable $lots = []): QualityCase
    {
        return DB::transaction(function () use ($data, $by, $lots) {
            DB::statement('LOCK TABLE quality_cases IN SHARE ROW EXCLUSIVE MODE');
            $keep = fn (string $s) => $data['subject'] === $s;
            $risk = (bool) ($data['immediate_risk'] ?? false);
            $case = QualityCase::create([
                'case_no' => (int) QualityCase::max('case_no') + 1, 'source' => $data['source'], 'title' => mb_substr($data['title'], 0, 160), 'description' => isset($data['description']) ? mb_substr($data['description'], 0, 2000) : null,
                'subject' => $data['subject'], 'animal_id' => $keep('animal') ? $data['animal_id'] : null, 'milk_lot_id' => $keep('milk_lot') ? $data['milk_lot_id'] : null,
                'feed_item_id' => $keep('feed_item') ? $data['feed_item_id'] : null, 'medicine_id' => $keep('medicine') ? $data['medicine_id'] : null,
                'batch' => $keep('medicine') ? ($data['batch'] ?? null) : null, 'process' => $keep('process') ? $data['process'] : null, 'incident_id' => $data['incident_id'] ?? null,
                'immediate_risk' => $risk, 'severity' => $data['severity'], 'status' => $risk ? 'contained' : 'investigating',
                'opened_by' => $by->name, 'opened_on' => now()->toDateString(),
            ]);
            if ($risk && ! empty($data['containment'])) {
                self::contain($case, $data['containment'], $by);
            }
            foreach ($lots as $lot) {
                self::contain($case, 'hold', $by, $lot);
            }
            if (in_array($data['severity'], ['high', 'critical'], true)) {
                self::task("Quality case {$case->label()}: {$case->title}", $data['severity'], 'Management', $case->subjectName().'. Gather the root cause and evidence, then plan the CAPA.', $case);
            }
            if ($case->animal) {
                Farm::event($case->animal, 'health', "Quality case {$case->label()}: {$case->title}", $by->name);
            }
            Farm::log($by, "Opened quality case {$case->label()}", '/quality?tab=cases');

            return $case;
        });
    }

    /**
     * Place hold / isolate / recall. A held product cannot sell or be used until the lead releases it.
     * $lot holds a milk lot on a case about something else (e.g. the doe whose milk is in it).
     */
    public static function contain(QualityCase $case, string $kind, User $by, ?MilkLot $lot = null): QualityHold
    {
        $subject = $lot ? 'milk_lot' : $case->subject;
        if ($subject === 'process') {
            throw ValidationException::withMessages(['containment' => 'A process has nothing to hold. Choose No for immediate risk, or record the affected animal, lot, feed or medicine.']);
        }
        if ($kind === 'isolate' && $subject !== 'animal') {
            throw ValidationException::withMessages(['containment' => 'Only an animal is isolated. Hold or recall a product.']);
        }
        if ($kind === 'recall' && $subject !== 'milk_lot') {
            throw ValidationException::withMessages(['containment' => 'A recall is for a milk lot that has left the farm. Hold feed or medicine instead.']);
        }
        $hold = QualityHold::create([
            'case_id' => $case->id, 'kind' => $kind, 'subject' => $subject, 'animal_id' => $lot ? null : $case->animal_id, 'milk_lot_id' => $lot?->id ?? $case->milk_lot_id,
            'feed_item_id' => $lot ? null : $case->feed_item_id, 'medicine_id' => $lot ? null : $case->medicine_id, 'batch' => $lot ? null : $case->batch, 'placed_by' => $by->name, 'placed_at' => now(),
        ]);
        if ($lot ??= $case->milkLot) {
            if ($kind === 'recall') {
                $buyers = $lot->dispatches()->with('customer')->get()->map(fn ($d) => $d->kind === 'processing' ? 'Processing' : ($d->customer?->name ?? $d->buyer ?? 'unknown buyer'))->unique()->implode(', ');
                $lot->update(['status' => 'recalled', 'hold_reason' => "Quality {$case->label()}: {$case->title}"]);
                self::task("Milk recall {$lot->code}: notify buyers", 'critical', 'Management', "Quality {$case->label()}. Buyers: ".($buyers ?: 'none yet').'. Settle each sale (refund or credit) on the lot\'s trace.', $case);
            } elseif (in_array($lot->status, ['pending_qc', 'stored', 'released'], true)) {
                $lot->update(['status' => 'hold', 'hold_reason' => "Quality {$case->label()}: {$case->title}"]);
            }
        }
        if ($kind === 'isolate' && $case->animal) {
            self::task("Isolate {$case->animal->farm_number} (quality {$case->label()})", 'high', 'Veterinary', 'Move to the isolation PEN and assess under the vet plan as the vet decides.', $case, $case->animal_id);
        }

        return $hold;
    }

    public static function task(string $title, string $priority, string $department, string $notes, QualityCase $case, ?string $animalId = null, ?int $userId = null): void
    {
        Task::create([
            'title' => mb_substr($title, 0, 190), 'type' => 'other', 'priority' => $priority, 'status' => 'open', 'due_on' => now()->toDateString(),
            'animal_id' => $animalId ?? $case->animal_id, 'department_id' => Department::where('name', $department)->value('id'), 'user_id' => $userId, 'notes' => mb_substr($notes, 0, 500),
        ]);
    }
}
