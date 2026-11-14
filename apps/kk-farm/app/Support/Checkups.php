<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\HealthCase;
use App\Models\HealthCheckup;
use App\Models\HealthRecord;
use App\Models\Mating;
use App\Models\PregnancyCheck;
use App\Models\User;
use App\Models\WeightRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * D-095: routine health checkups. One checkup becomes the same records a person would make one by one: a weighing,
 * a pregnancy check that updates Breeding (and so the dashboard), an open report or a health case for what was found.
 * The system flags readings outside normal; the vet decides.
 */
final class Checkups
{
    public static function interval(): int
    {
        return (int) (Tenant::org()?->checkup_interval_days ?: 14);
    }

    /** D-104: a drop of 3% or more (or any drop of 0.3 KG or more for a pregnant doe) is a warning sign. */
    public const WEIGHT_DROP_PCT = 3;

    /** @return array{kg: float, on: string}|null the goat's last weighing before a date */
    public static function lastWeight(Animal $goat, string $before): ?array
    {
        $w = WeightRecord::where('animal_id', $goat->id)->where('measured_on', '<', $before)->orderByDesc('measured_on')->orderByDesc('id')->first();

        return $w ? ['kg' => (float) $w->weight_kg, 'on' => $w->measured_on->toDateString()] : null;
    }

    /** "Weight down 0.9 KG (4.6%) since 28 Oct", or null when it is not a warning. */
    public static function weightDrop(Animal $goat, string $on, ?float $kg): ?string
    {
        if (! $kg) {
            return null;
        }
        $last = self::lastWeight($goat, $on);
        if (! $last || $kg >= $last['kg']) {
            return null;
        }
        $drop = $last['kg'] - $kg;
        $pct = $drop / $last['kg'] * 100;
        $pregnant = self::dayOfPregnancy($goat, $on) !== null;
        if ($pct < self::WEIGHT_DROP_PCT && ! ($pregnant && $drop >= 0.3)) {
            return null;
        }

        return 'Weight down '.round($drop, 1).' KG ('.round($pct, 1).'%) since '.Carbon::parse($last['on'])->format('d/m').($pregnant ? ' while pregnant' : '');
    }

    /**
     * D-101: a checkup of a pregnant doe always carries her day of pregnancy, counted from the mating, even when no
     * ultrasound was done that day. Typed days on a pregnant ultrasound win (they may set the mating date).
     */
    public static function dayOfPregnancy(Animal $doe, string $date): ?int
    {
        if ($doe->sex !== 'female') {
            return null;
        }
        $m = Mating::where('dam_id', $doe->id)->where('status', 'pregnant')->where('mated_on', '<=', $date)->orderByDesc('mated_on')->first();

        return $m ? (int) $m->mated_on->diffInDays(Carbon::parse($date)) : null;
    }

    /** Fill the day of pregnancy on checkups that have none (all of them, or one goat's). Returns how many were filled. */
    public static function fillPregnancyDays(?Animal $only = null): int
    {
        $n = 0;
        HealthCheckup::with('animal')->whereNull('days_pregnant')->when($only, fn ($q) => $q->where('animal_id', $only->id))->get()
            ->each(function (HealthCheckup $c) use (&$n) {
                $day = self::dayOfPregnancy($c->animal, $c->checked_on->toDateString());
                if ($day !== null) {
                    $c->update(['days_pregnant' => $day]);
                    $n++;
                }
            });

        return $n;
    }

    /**
     * D-102: what the guided health entry needs (the same form on the Health page, the goat page and after a scan).
     *
     * @param  Collection<int, Animal>  $goats
     */
    public static function entryData(User $user, Collection $goats): array
    {
        $pregnant = self::pregnancies();
        $goats->loadMissing('pen.shed');

        return [
            'goats' => $goats->map(fn (Animal $a) => [
                'id' => $a->id, 'number' => $a->farm_number, 'name' => $a->name, 'sex' => $a->sex, 'pen' => $a->pen?->fullName(), 'repro' => $a->repro_status,
                'last' => null, 'due' => false, 'next' => null, 'recheck' => null, 'matedOn' => isset($pregnant[$a->id]) ? $pregnant[$a->id]->mated_on->toDateString() : null,
                'weights' => $a->weights()->get(['measured_on', 'weight_kg'])->map(fn ($w) => ['on' => $w->measured_on->toDateString(), 'kg' => (float) $w->weight_kg])->values()->all(), // D-104
            ])->values()->all(),
            'recent' => [], 'interval' => self::interval(), 'normal' => HealthCheckup::NORMAL, 'gestation' => (int) (Tenant::org()?->gestation_days ?: 150),
            'sires' => Animal::active()->where('sex', 'male')->orderBy('farm_number')->get(['id', 'farm_number'])->map(fn ($a) => ['id' => $a->id, 'number' => $a->farm_number])->all(),
            'conditions' => \App\Models\Disease::names(), 'severities' => HealthCase::SEVERITIES,
            'ultrasound' => PregnancyCheck::STATUSES, 'viability' => PregnancyCheck::VIABILITY, // D-167
            'canCheck' => $user->passes('health_checkup'), 'canOpenCase' => $user->passes('open_cases'),
            'canEntry' => $user->passes('health_entry'), // D-252: New health entry
        ];
    }

    /** @return Collection<string, Mating> each doe's current pregnancy (a pregnant mating), by goat id (D-100) */
    public static function pregnancies(): Collection
    {
        return Mating::where('status', 'pregnant')->orderByDesc('mated_on')->get()->unique('dam_id')->keyBy('dam_id');
    }

    /**
     * D-100: one pregnancy as a thread: day N of the gestation, expected kidding, and every check since mating with its
     * day of pregnancy, weight and temperature, for the goat page and its graph.
     */
    public static function pregnancyThread(Animal $doe): ?array
    {
        $m = Mating::with('sire')->where('dam_id', $doe->id)->where('status', 'pregnant')->orderByDesc('mated_on')->first();
        if (! $m) {
            return null;
        }
        $gestation = (int) (Tenant::org()?->gestation_days ?? 150);
        $checks = HealthCheckup::where('animal_id', $doe->id)->where('checked_on', '>=', $m->mated_on)->orderBy('checked_on')->orderBy('id')->get();
        $weights = WeightRecord::where('animal_id', $doe->id)->where('measured_on', '>=', $m->mated_on)->orderBy('measured_on')->get();

        return [
            'matingId' => $m->id, 'matedOnIso' => $m->mated_on->toDateString(), // D-161: record the outcome from the goat page
            'matedOn' => $m->mated_on->format('d/m/Y'), 'expected' => $m->expected_kidding_on?->format('d/m/Y'), 'sire' => $m->sire?->farm_number,
            'day' => (int) $m->mated_on->diffInDays(now()->startOfDay()), 'gestation' => $gestation,
            'daysLeft' => $m->expected_kidding_on ? (int) now()->startOfDay()->diffInDays($m->expected_kidding_on, false) : null,
            'alertDays' => (int) (Tenant::org()?->kidding_alert_days ?: 10), // D-103
            'checks' => $checks->map(fn (HealthCheckup $c) => [
                'on' => $c->checked_on->format('d/m/Y'), 'day' => (int) $m->mated_on->diffInDays($c->checked_on), 'weight' => $c->weight_kg, 'temp' => $c->temperature_f,
                'ultrasound' => $c->ultrasound, 'fetuses' => $c->fetuses, 'by' => $c->examiner,
            ])->values()->all(),
            'weights' => $weights->map(fn (WeightRecord $w) => ['day' => (int) $m->mated_on->diffInDays($w->measured_on), 'kg' => (float) $w->weight_kg, 'on' => $w->measured_on->format('d/m')])->values()->all(),
        ];
    }

    /** @return Collection<string, HealthCheckup> each goat's latest checkup, by goat id */
    public static function latest(): Collection
    {
        return HealthCheckup::whereIn('id', DB::table('health_checkups')->selectRaw('max(id)')->groupBy('animal_id'))->get()->keyBy('animal_id');
    }

    /** Goats on the farm with no checkup, or none in the last interval days. */
    public static function due(): Collection
    {
        $latest = self::latest();
        $since = now()->subDays(self::interval())->toDateString();

        return Animal::with('pen.shed')->active()->orderBy('farm_number')->get()
            ->filter(fn (Animal $a) => ! isset($latest[$a->id]) || $latest[$a->id]->checked_on->toDateString() <= $since)->values();
    }

    /** @param array<string, mixed> $data validated input */
    public static function record(Animal $goat, array $data, User $by): HealthCheckup
    {
        // D-167: the KK Farms ultrasound status decides the simple result (pregnant / not pregnant / not clear).
        if (! empty($data['ultrasound_status'])) {
            $data['ultrasound'] = ['pregnant' => 'pregnant', 'not_pregnant' => 'not_pregnant', 'pregnancy_loss' => 'not_pregnant'][PregnancyCheck::RESULT_OF[$data['ultrasound_status']]] ?? 'inconclusive';
        } elseif (! empty($data['ultrasound'])) {
            $data['ultrasound_status'] = PregnancyCheck::STATUS_OF[$data['ultrasound']];
        }

        return DB::transaction(function () use ($goat, $data, $by) {
            $on = Carbon::parse($data['checked_on'])->toDateString();
            // D-104: readings outside normal and a weight drop are warnings: the result cannot stay Healthy, and the vet is alerted.
            $drop = self::weightDrop($goat, $on, isset($data['weight_kg']) && $data['weight_kg'] !== '' ? (float) $data['weight_kg'] : null);
            $temp = isset($data['temperature_f']) && $data['temperature_f'] !== '' ? (float) $data['temperature_f'] : null;
            [$low, $high] = HealthCheckup::NORMAL['temperature_f'];
            $warnings = array_values(array_filter([
                $temp !== null && $temp > $high ? "Temperature {$temp} °F (high)" : ($temp !== null && $temp < $low ? "Temperature {$temp} °F (low)" : null), $drop,
            ]));
            if ($warnings && ($data['outcome'] ?? 'healthy') === 'healthy') {
                $data['outcome'] = 'monitor';
            }
            $checkup = HealthCheckup::create([
                'animal_id' => $goat->id, 'checked_on' => $on, 'temperature_f' => $data['temperature_f'] ?? null, 'body_score' => $data['body_score'] ?? null, 'weight_kg' => $data['weight_kg'] ?? null,
                'ultrasound' => $goat->sex === 'female' ? ($data['ultrasound'] ?? null) : null, 'fetuses' => $data['fetuses'] ?? null,
                'ultrasound_status' => $goat->sex === 'female' ? ($data['ultrasound_status'] ?? null) : null, 'viability' => $data['viability'] ?? null,
                'days_pregnant' => $data['days_pregnant'] ?? null, 'findings' => $data['findings'] ?? null, 'outcome' => $data['outcome'], 'examiner' => $by->name,
            ]);
            $parts = [];

            if (! empty($data['weight_kg'])) {
                WeightRecord::create(['animal_id' => $goat->id, 'measured_on' => $on, 'weight_kg' => $data['weight_kg'], 'body_score' => $data['body_score'] ?? null, 'context' => 'checkup', 'measured_by' => $by->name]);
                $parts[] = "{$data['weight_kg']} KG";
            }
            if ($checkup->ultrasound) {
                $mating = self::pregnancy($goat, $checkup, $data, $by);
                $checkup->update(['mating_id' => $mating?->id]);
                $parts[] = 'ultrasound: '.strtolower(PregnancyCheck::STATUSES[$checkup->ultrasound_status] ?? HealthCheckup::ULTRASOUND[$checkup->ultrasound]).($checkup->fetuses ? " ({$checkup->fetuses} fetus".($checkup->fetuses > 1 ? 'es' : '').')' : '');
            }

            $flags = array_values(array_unique([...$checkup->flags(), ...array_filter([$drop])]));
            if (! empty($data['open_case'])) {
                DB::statement('LOCK TABLE health_cases IN SHARE ROW EXCLUSIVE MODE');
                $case = HealthCase::create([
                    'case_no' => (int) HealthCase::max('case_no') + 1, 'animal_id' => $goat->id, 'onset_on' => $on, 'condition' => $data['condition'],
                    'symptoms' => trim(implode('. ', array_filter([implode(', ', $flags), $data['findings'] ?? null]))) ?: null, 'severity' => $data['severity'] ?? 'moderate',
                    'isolated' => (bool) ($data['isolated'] ?? false), 'vet' => $by->name, 'recorded_by' => $by->name, 'notes' => 'Found at the health checkup on '.Carbon::parse($on)->format('d/m/Y').'.',
                ]);
                $checkup->update(['health_case_id' => $case->id]);
                $parts[] = "case {$case->label()} opened: {$case->condition}";
            } elseif ($checkup->outcome !== 'healthy' || $flags) {
                // Not healthy, or a reading outside normal, but no case yet: an open report keeps it in view.
                HealthRecord::create([
                    'animal_id' => $goat->id, 'kind' => 'observation', 'occurred_on' => $on, 'title' => 'Checkup: '.($flags ? implode(', ', $flags) : HealthCheckup::OUTCOMES[$checkup->outcome]),
                    'notes' => $data['findings'] ?? null, 'severity' => $checkup->outcome === 'sick' ? 'high' : 'medium', 'recorded_by' => $by->name, 'recorded_role' => $by->roleLabel(), 'status' => 'open',
                ]);
            }

            self::followUp($goat, $checkup, $data, $by);
            if ($warnings) {
                self::alertVet($goat, $checkup, $warnings, $by);
            }
            self::fillPregnancyDays($goat); // D-101
            Recovery::review($goat, $by->name); // D-250: a normal reading or a Healthy result answers an earlier watch
            self::syncHealth($goat);
            $readings = array_filter([
                $checkup->temperature_f !== null ? "{$checkup->temperature_f} °F" : null, $checkup->body_score ? "body {$checkup->body_score}/5" : null,
            ]);
            Farm::event($goat, 'health', 'Health checkup: '.HealthCheckup::OUTCOMES[$checkup->outcome].(($readings || $parts) ? ' · '.implode(' · ', [...$readings, ...$parts]) : ''), $by->name, Carbon::parse($on));

            return $checkup;
        });
    }

    /** D-104: an urgent task for the vets to examine the goat today, for what the checkup flagged. */
    private static function alertVet(Animal $goat, HealthCheckup $c, array $warnings, User $by): void
    {
        $fever = collect($warnings)->contains(fn ($w) => str_contains($w, '(high)'));
        \App\Models\Task::create([
            'title' => mb_substr("Vet check {$goat->farm_number}: ".implode('; ', $warnings), 0, 190), 'type' => 'health', 'priority' => $fever ? 'critical' : 'high', 'status' => 'open',
            'due_on' => now()->toDateString(), 'animal_id' => $goat->id, 'pen_id' => $goat->pen_id, 'department_id' => \App\Models\Department::where('name', 'Veterinary')->value('id'),
            'notes' => mb_substr('Flagged at the checkup on '.$c->checked_on->format('d/m/Y')." by {$by->name}.", 0, 500),
        ]);
    }

    /** D-099: after the checkup: a recheck on a date becomes a task for the vets (moved or cancelled when corrected). */
    private static function followUp(Animal $goat, HealthCheckup $c, array $data, User $by): void
    {
        $on = $data['follow_up_on'] ?? null;
        $task = $c->follow_up_task_id ? \App\Models\Task::find($c->follow_up_task_id) : null;
        if (! $on) {
            if ($task && in_array($task->status, \App\Models\Task::OPEN, true)) {
                $task->update(['status' => 'cancelled', 'completed_at' => now(), 'completed_by' => $by->name]);
            }
            $c->update(['follow_up_on' => null, 'follow_up_note' => null, 'follow_up_task_id' => $task?->id]);

            return;
        }
        $title = mb_substr("Recheck {$goat->farm_number}".(($data['follow_up_note'] ?? '') !== '' ? ": {$data['follow_up_note']}" : ''), 0, 190);
        if ($task && in_array($task->status, \App\Models\Task::OPEN, true)) {
            $task->update(['title' => $title, 'due_on' => $on]);
        } else {
            $task = \App\Models\Task::create([
                'title' => $title, 'type' => 'health', 'priority' => $c->outcome === 'sick' ? 'high' : 'medium', 'status' => 'open', 'due_on' => $on,
                'animal_id' => $goat->id, 'pen_id' => $goat->pen_id, 'department_id' => \App\Models\Department::where('name', 'Veterinary')->value('id'),
                'notes' => mb_substr('Follow-up of the checkup on '.$c->checked_on->format('d/m/Y')." by {$by->name}.", 0, 500),
            ]);
        }
        $c->update(['follow_up_on' => $on, 'follow_up_note' => $data['follow_up_note'] ?? null, 'follow_up_task_id' => $task->id]);
    }

    /**
     * D-099: correct a checkup (the examiner, or the administrator). Every changed field is kept as a revision with
     * who and why; what the checkup made follows: the weighing, the pregnancy (mating dates, check) and the recheck task.
     *
     * @return array<string, array{0: mixed, 1: mixed}> the changes
     */
    public static function correct(HealthCheckup $c, array $data, User $by): array
    {
        return DB::transaction(function () use ($c, $data, $by) {
            $goat = $c->animal;
            $labels = ['checked_on' => 'Date', 'temperature_f' => 'Temperature °F', 'body_score' => 'Body condition', 'weight_kg' => 'Weight KG', 'ultrasound' => 'Ultrasound',
                'days_pregnant' => 'Days pregnant', 'fetuses' => 'Fetuses', 'findings' => 'Findings', 'outcome' => 'Result', 'follow_up_on' => 'Recheck', 'follow_up_note' => 'Recheck note'];
            $old = ['checked_on' => $c->checked_on->toDateString(), 'weight_kg' => $c->weight_kg, 'ultrasound' => $c->ultrasound, 'days_pregnant' => $c->days_pregnant];
            $changes = [];
            foreach ($labels as $key => $label) {
                if (! array_key_exists($key, $data)) {
                    continue;
                }
                $before = $c->{$key} instanceof \DateTimeInterface ? $c->{$key}->toDateString() : $c->{$key};
                $after = $data[$key] === '' ? null : $data[$key];
                if ($key === 'ultrasound' && $goat->sex !== 'female') {
                    $after = null;
                }
                $same = is_numeric($before) && is_numeric($after) ? (float) $before === (float) $after : (string) ($before ?? '') === (string) ($after ?? '');
                if (! $same) {
                    $changes[$label] = [$before, $after];
                }
            }
            if (! $changes) {
                return [];
            }
            $c->update(array_intersect_key($data, array_flip(['checked_on', 'temperature_f', 'body_score', 'weight_kg', 'ultrasound', 'days_pregnant', 'fetuses', 'findings', 'outcome'])) + ($goat->sex !== 'female' ? ['ultrasound' => null] : []));
            $c->refresh();
            $on = $c->checked_on->toDateString();

            // The weighing it made follows.
            $weighing = WeightRecord::where('animal_id', $goat->id)->where('context', 'checkup')->whereDate('measured_on', $old['checked_on'])->orderByDesc('id')->first();
            if ($weighing && $c->weight_kg) {
                $weighing->update(['measured_on' => $on, 'weight_kg' => $c->weight_kg, 'body_score' => $c->body_score]);
            } elseif (! $weighing && $c->weight_kg) {
                WeightRecord::create(['animal_id' => $goat->id, 'measured_on' => $on, 'weight_kg' => $c->weight_kg, 'body_score' => $c->body_score, 'context' => 'checkup', 'measured_by' => $c->examiner]);
            }

            // The pregnancy it made follows.
            $mating = $c->mating_id ? Mating::find($c->mating_id) : null;
            $madeHere = $mating && str_starts_with((string) $mating->notes, 'Found pregnant at the health checkup');
            $gestation = (int) (Tenant::org()?->gestation_days ?? 150);
            if ($c->ultrasound === 'pregnant' && $mating) {
                $update = ['status' => 'pregnant'];
                if ($madeHere && $c->days_pregnant) {
                    $mated = $c->checked_on->copy()->subDays($c->days_pregnant);
                    $update += ['mated_on' => $mated, 'expected_kidding_on' => $mated->copy()->addDays($gestation)];
                }
                $mating->update($update);
                $goat->update(['repro_status' => 'pregnant', 'repro_state' => Classification::LEGACY_REPRO['pregnant'] ?? 'pregnant']);
            } elseif ($c->ultrasound === 'pregnant' && ! $mating) {
                $c->update(['mating_id' => self::pregnancy($goat, $c, $data, $by)?->id]);
            } elseif ($old['ultrasound'] === 'pregnant' && $mating) {
                $mating->update(['status' => 'not_pregnant']);
                if ($goat->repro_status === 'pregnant') {
                    $goat->update(['repro_status' => 'open', 'repro_state' => Classification::LEGACY_REPRO['open'] ?? null]);
                }
            }
            if ($mating) {
                PregnancyCheck::where('mating_id', $mating->id)->where('notes', 'At the health checkup.')->whereDate('checked_on', $old['checked_on'])
                    ->update(['checked_on' => $on, 'result' => $c->ultrasound ?? 'inconclusive', 'fetuses' => $c->fetuses]);
            }

            if (array_key_exists('follow_up_on', $data)) {
                self::followUp($goat, $c, $data, $by);
            }
            // A reading now outside normal with nothing open yet: an open report keeps it in view.
            if ($c->flags() && ! $c->health_case_id && ! HealthRecord::where('animal_id', $goat->id)->where('status', 'open')->exists()) {
                HealthRecord::create([
                    'animal_id' => $goat->id, 'kind' => 'observation', 'occurred_on' => $on, 'title' => 'Checkup: '.implode(', ', $c->flags()), 'notes' => $c->findings,
                    'severity' => 'medium', 'recorded_by' => $by->name, 'recorded_role' => $by->roleLabel(), 'status' => 'open',
                ]);
            }
            if ($c->ultrasound !== 'pregnant') {
                $c->update(['days_pregnant' => self::dayOfPregnancy($goat, $on)]); // D-101: a check without a pregnant ultrasound carries the counted day
            }
            self::fillPregnancyDays($goat);
            self::syncHealth($goat);
            Revisions::log('checkup', $c->id, $goat, $changes, $by, $data['reason'] ?? null);

            return $changes;
        });
    }

    /**
     * A pregnant ultrasound confirms the doe's current mating or, with none, records one (sire as chosen or unknown),
     * dated back from the months pregnant, with the farm's gestation for the expected kidding. Not pregnant closes an
     * open or assumed pregnancy. Each result is also a pregnancy check in Breeding.
     */
    private static function pregnancy(Animal $doe, HealthCheckup $c, array $data, User $by): ?Mating
    {
        $org = Tenant::org();
        $gestation = (int) ($org->gestation_days ?? 150);
        $on = $c->checked_on->copy();
        $mating = Mating::where('dam_id', $doe->id)->whereIn('status', ['open', 'pregnant', 'inconclusive'])->orderByDesc('mated_on')->first();

        $status = $c->ultrasound_status ?? PregnancyCheck::STATUS_OF[$c->ultrasound] ?? 'INC';
        if ($status === 'CPL') {
            // D-167: a loss the vet confirmed: recorded like any pregnancy loss (isolation, monitoring, vet and biosecurity tasks).
            if ($mating) {
                self::scanRecord($mating, $doe, $c, $status, $by);
                PregnancyLoss::record($mating, $on, $mating->mated_on->diffInDays($on) < 45 ? 'EEL' : 'ABT', $by, $c->findings);
            }

            return $mating;
        }
        if ($c->ultrasound === 'pregnant') {
            $days = $c->days_pregnant;
            if (! $mating) {
                $mated = $on->copy()->subDays($days ?: 60);
                $mating = Mating::create([
                    'dam_id' => $doe->id, 'sire_id' => $data['sire_id'] ?? null, 'mated_on' => $mated, 'method' => 'natural', 'status' => 'pregnant',
                    'expected_kidding_on' => $mated->copy()->addDays($gestation), 'recorded_by' => $by->name,
                    'notes' => 'Found pregnant at the health checkup on '.$on->format('d/m/Y').($days ? ", about {$days} days pregnant" : '').'.',
                ]);
            } else {
                $update = ['status' => 'pregnant'];
                if ($days && ! $mating->sire_id && $mating->status !== 'pregnant') {
                    $update['expected_kidding_on'] = $on->copy()->subDays($days)->addDays($gestation);
                }
                $mating->update($update);
            }
            $doe->update(['repro_status' => 'pregnant', 'repro_state' => Classification::LEGACY_REPRO['pregnant'] ?? 'pregnant']);
        } elseif ($c->ultrasound === 'not_pregnant' && $mating) {
            $mating->update(['status' => 'not_pregnant']);
            if (in_array($doe->repro_status, ['pregnant', 'mated', 'check_due', 'expected_kidding'], true)) {
                $doe->update(['repro_status' => 'open', 'repro_state' => Classification::LEGACY_REPRO['open'] ?? null]);
            }
        }
        if ($mating) {
            self::scanRecord($mating, $doe, $c, $status, $by);
            // D-167: the same follow-up tasks as an ultrasound recorded in Breeding.
            UltrasoundTasks::raise($mating->fresh(), $doe, $status, $c->fetuses, in_array($status, PregnancyCheck::REPEAT, true) ? $on->copy()->addDays(14)->toDateString() : null);
        }

        return $mating;
    }

    /** D-167: the checkup's ultrasound as a Breeding ultrasound record. */
    private static function scanRecord(Mating $mating, Animal $doe, HealthCheckup $c, string $status, User $by): PregnancyCheck
    {
        return PregnancyCheck::create([
            'mating_id' => $mating->id, 'dam_id' => $doe->id, 'checked_on' => $c->checked_on, 'method' => 'ultrasound', 'result' => PregnancyCheck::RESULT_OF[$status],
            'ultrasound_status' => $status, 'viability' => $c->viability, 'pregnancy_day' => (int) $mating->mated_on->diffInDays($c->checked_on),
            'action' => PregnancyCheck::action($status, $c->fetuses), 'fetuses' => $c->fetuses, 'examiner' => $by->name, 'notes' => 'At the health checkup.',
            'next_check_on' => in_array($status, PregnancyCheck::REPEAT, true) ? $c->checked_on->copy()->addDays(14) : null,
        ]);
    }

    /** Same rule as the Health page: the goat's health follows its open cases and reports. */
    public static function syncHealth(Animal $animal): void
    {
        $active = HealthCase::where('animal_id', $animal->id)->whereIn('status', HealthCase::ACTIVE)->get();
        $flag = match (true) {
            // D-172: an intake quarantine alone is a healthy new arrival under watch, not a sick goat in isolation.
            $active->contains(fn ($c) => $c->isolated && $c->condition !== Intake::CONDITION) => 'quarantine',
            $active->isNotEmpty() && $active->every(fn ($c) => $c->condition === Intake::CONDITION) => 'monitor',
            $active->whereIn('status', ['open', 'under_treatment'])->isNotEmpty() => 'sick',
            $active->isNotEmpty() => 'recovering',
            HealthRecord::where('animal_id', $animal->id)->where('status', 'open')->exists() => 'monitor',
            default => 'healthy',
        };
        $animal->update(['health_flag' => $flag]);
    }
}
