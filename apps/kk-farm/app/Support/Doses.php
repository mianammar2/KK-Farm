<?php

namespace App\Support;

use App\Models\Medicine;
use App\Models\Task;
use App\Models\Treatment;
use App\Models\TreatmentDose;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * D-304: a prescription's doses (workbook Vet_Orders → Medicine_Admin). The vet chooses how often and for how many
 * days; every dose is planned, the first is the treatment itself. A dose given is taken from the medicine store
 * (first-expiring batch, D-074); a dose missed or held, or an adverse reaction, tells the prescribing vet (triage rule
 * AL-012). The vet decides; nothing here changes the prescription.
 */
final class Doses
{
    /** key => [label, doses a day, every N days] */
    public const FREQUENCIES = [
        'once' => ['Once (single dose)', 1, 0],
        'sid' => ['Once a day', 1, 1],
        'bid' => ['Twice a day', 2, 1],
        'tid' => ['Three times a day', 3, 1],
        'q48h' => ['Every 2 days', 1, 2],
        'q72h' => ['Every 3 days', 1, 3],
        'weekly' => ['Once a week', 1, 7],
    ];

    public const SLOTS = [1 => ['morning'], 2 => ['morning', 'evening'], 3 => ['morning', 'midday', 'evening']];

    /** The frequency key for what was saved (a key, or older free text such as "twice daily"). */
    public static function key(?string $frequency): ?string
    {
        $f = strtolower(trim((string) $frequency));
        if (isset(self::FREQUENCIES[$f])) {
            return $f;
        }

        return match (true) {
            $f === '' => null,
            str_contains($f, 'three') || str_contains($f, 'tid') => 'tid',
            str_contains($f, 'twice') || str_contains($f, 'bid') || str_contains($f, '12') => 'bid',
            str_contains($f, 'week') => 'weekly',
            str_contains($f, '72') || str_contains($f, '3 day') => 'q72h',
            str_contains($f, '48') || str_contains($f, '2 day') || str_contains($f, 'other day') => 'q48h',
            str_contains($f, 'once') && ! str_contains($f, 'day') => 'once',
            default => 'sid',
        };
    }

    /** Plan every dose of the prescription; the first one (the treatment) is recorded as given. */
    public static function plan(Treatment $t, User $by): void
    {
        $key = self::key($t->frequency);
        $days = max(1, (int) ($t->days_prescribed ?: 1));
        [, $perDay, $every] = self::FREQUENCIES[$key ?? 'once'];
        $start = Carbon::parse($t->given_on);
        $n = 0;
        $rows = [];
        if ($key === null || $key === 'once') {
            $rows[] = [$start, self::SLOTS[1][0]];
        } else {
            for ($d = 0; $d < $days; $d += max(1, $every)) {
                foreach (self::SLOTS[$perDay] as $slot) {
                    $rows[] = [$start->copy()->addDays($d), $slot];
                }
            }
        }
        foreach ($rows as [$on, $slot]) {
            $n++;
            TreatmentDose::create(['treatment_id' => $t->id, 'animal_id' => $t->animal_id, 'number' => $n, 'due_on' => $on->toDateString(), 'slot' => $slot] + ($n === 1 ? [
                'status' => 'given', 'done_at' => now(), 'qty' => $t->qty_used, 'done_by' => $t->recorded_by,
            ] : []));
        }
        $next = TreatmentDose::where('treatment_id', $t->id)->where('status', 'due')->orderBy('number')->value('due_on');
        $t->update(['next_dose_on' => $next]);
    }

    /** Give a dose: from stock (first-expiring batch) when the prescription names a store medicine. */
    public static function give(TreatmentDose $dose, User $by, ?float $qty, bool $adverse, ?string $notes): float
    {
        abort_unless($dose->status === 'due', 422, 'This dose is already recorded.');
        $t = $dose->treatment;
        $short = 0.0;
        DB::transaction(function () use ($dose, $by, $qty, $adverse, $notes, $t, &$short) {
            $qty ??= $t->qty_per_dose !== null ? (float) $t->qty_per_dose : ($t->qty_used !== null ? (float) $t->qty_used : null);
            $batch = null;
            if ($t->medicine_id && $qty) {
                $medicine = Medicine::find($t->medicine_id);
                $first = MedicineStock::batches($medicine->id)[$medicine->id][0]['batch'] ?? null;
                $short = MedicineStock::issue($medicine, $qty, ['moved_on' => now()->toDateString(), 'treatment_id' => $t->id, 'reference' => "Dose {$dose->number} of {$t->product}", 'recorded_by' => $by->name]);
                $batch = $first;
            }
            $dose->update(['status' => 'given', 'done_at' => now(), 'qty' => $qty, 'batch' => $batch, 'done_by' => $by->name, 'adverse' => $adverse, 'notes' => $notes]);
            $animal = $dose->animal;
            Farm::event($animal, 'health', "Dose {$dose->number} given: {$t->product} {$t->dose}".($adverse ? ' · adverse reaction' : ''), $by->name);
            if ($adverse) {
                self::tellVet($t, $animal->farm_number, "Adverse reaction to {$t->product} (dose {$dose->number})".($notes ? ": $notes" : ''), 'critical');
            }
            self::next($t);
        });

        return $short;
    }

    /** A dose missed or held back: recorded with why, and the prescribing vet is told (AL-012). */
    public static function skip(TreatmentDose $dose, User $by, string $status, string $why): void
    {
        abort_unless($dose->status === 'due' && in_array($status, ['missed', 'held'], true), 422);
        $t = $dose->treatment;
        DB::transaction(function () use ($dose, $by, $status, $why, $t) {
            $dose->update(['status' => $status, 'done_at' => now(), 'done_by' => $by->name, 'notes' => $why]);
            Farm::event($dose->animal, 'health', 'Dose '.$dose->number.' '.($status === 'missed' ? 'missed' : 'held').": {$t->product} · $why", $by->name);
            self::tellVet($t, $dose->animal->farm_number, ($status === 'missed' ? 'Missed dose' : 'Dose held back')." {$dose->number} of {$t->product}: $why", 'high');
            self::next($t);
        });
    }

    private static function next(Treatment $t): void
    {
        $t->update(['next_dose_on' => TreatmentDose::where('treatment_id', $t->id)->where('status', 'due')->orderBy('number')->value('due_on')]);
    }

    /** A task for the vet who prescribed (or every vet's queue when the name is not a user). */
    private static function tellVet(Treatment $t, string $number, string $what, string $priority): void
    {
        $vet = User::where('name', $t->recorded_by)->where('role', 'vet')->value('id');
        Task::create(['title' => mb_substr("Medicine: $number · $what", 0, 190), 'type' => 'health', 'priority' => $priority, 'status' => 'open', 'due_on' => now()->toDateString(),
            'animal_id' => $t->animal_id, 'user_id' => $vet, 'department_id' => $vet ? null : \App\Models\Department::where('name', 'Veterinary')->value('id'),
            'notes' => 'Triage AL-012: notify the prescribing veterinarian; document the corrective action.']);
    }

    /** Progress of one treatment: given, missed, held, due, total. */
    public static function progress(int $treatmentId): array
    {
        $c = TreatmentDose::where('treatment_id', $treatmentId)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');

        return ['given' => (int) ($c['given'] ?? 0), 'missed' => (int) ($c['missed'] ?? 0), 'held' => (int) ($c['held'] ?? 0), 'due' => (int) ($c['due'] ?? 0), 'total' => (int) $c->sum()];
    }
}
