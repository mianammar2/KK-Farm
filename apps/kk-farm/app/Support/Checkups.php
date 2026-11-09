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
        return DB::transaction(function () use ($goat, $data, $by) {
            $on = Carbon::parse($data['checked_on'])->toDateString();
            $checkup = HealthCheckup::create([
                'animal_id' => $goat->id, 'checked_on' => $on, 'temperature_f' => $data['temperature_f'] ?? null, 'heart_rate' => $data['heart_rate'] ?? null,
                'respiration_rate' => $data['respiration_rate'] ?? null, 'body_score' => $data['body_score'] ?? null, 'weight_kg' => $data['weight_kg'] ?? null,
                'ultrasound' => $goat->sex === 'female' ? ($data['ultrasound'] ?? null) : null, 'fetuses' => $data['fetuses'] ?? null,
                'months_pregnant' => $data['months_pregnant'] ?? null, 'findings' => $data['findings'] ?? null, 'outcome' => $data['outcome'], 'examiner' => $by->name,
            ]);
            $parts = [];

            if (! empty($data['weight_kg'])) {
                WeightRecord::create(['animal_id' => $goat->id, 'measured_on' => $on, 'weight_kg' => $data['weight_kg'], 'body_score' => $data['body_score'] ?? null, 'context' => 'checkup', 'measured_by' => $by->name]);
                $parts[] = "{$data['weight_kg']} KG";
            }
            if ($checkup->ultrasound) {
                $mating = self::pregnancy($goat, $checkup, $data, $by);
                $checkup->update(['mating_id' => $mating?->id]);
                $parts[] = 'ultrasound: '.strtolower(HealthCheckup::ULTRASOUND[$checkup->ultrasound]).($checkup->fetuses ? " ({$checkup->fetuses} fetus".($checkup->fetuses > 1 ? 'es' : '').')' : '');
            }

            $flags = $checkup->flags();
            if (! empty($data['open_case'])) {
                DB::statement('LOCK TABLE health_cases IN SHARE ROW EXCLUSIVE MODE');
                $case = HealthCase::create([
                    'case_no' => (int) HealthCase::max('case_no') + 1, 'animal_id' => $goat->id, 'onset_on' => $on, 'condition' => $data['condition'],
                    'symptoms' => trim(implode('. ', array_filter([implode(', ', $flags), $data['findings'] ?? null]))) ?: null, 'severity' => $data['severity'] ?? 'moderate',
                    'isolated' => (bool) ($data['isolated'] ?? false), 'vet' => $by->name, 'recorded_by' => $by->name, 'notes' => 'Found at the health checkup on '.Carbon::parse($on)->format('j M Y').'.',
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

            self::syncHealth($goat);
            $readings = array_filter([
                $checkup->temperature_f !== null ? "{$checkup->temperature_f} °F" : null, $checkup->heart_rate ? "heart {$checkup->heart_rate}/min" : null,
                $checkup->respiration_rate ? "breathing {$checkup->respiration_rate}/min" : null, $checkup->body_score ? "body {$checkup->body_score}/5" : null,
            ]);
            Farm::event($goat, 'health', 'Health checkup: '.HealthCheckup::OUTCOMES[$checkup->outcome].(($readings || $parts) ? ' · '.implode(' · ', [...$readings, ...$parts]) : ''), $by->name, Carbon::parse($on));

            return $checkup;
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
        $mating = Mating::where('dam_id', $doe->id)->whereIn('status', ['open', 'pregnant'])->orderByDesc('mated_on')->first();

        if ($c->ultrasound === 'pregnant') {
            $months = $c->months_pregnant;
            if (! $mating) {
                $mated = $on->copy()->subDays((int) round(($months ?: 2) * 30));
                $mating = Mating::create([
                    'dam_id' => $doe->id, 'sire_id' => $data['sire_id'] ?? null, 'mated_on' => $mated, 'method' => 'natural', 'status' => 'pregnant',
                    'expected_kidding_on' => $mated->copy()->addDays($gestation), 'recorded_by' => $by->name,
                    'notes' => 'Found pregnant at the health checkup on '.$on->format('j M Y').($months ? ", about {$months} months" : '').'.',
                ]);
            } else {
                $update = ['status' => 'pregnant'];
                if ($months && ! $mating->sire_id && $mating->status !== 'pregnant') {
                    $update['expected_kidding_on'] = $on->copy()->subDays((int) round($months * 30))->addDays($gestation);
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
            PregnancyCheck::create([
                'mating_id' => $mating->id, 'dam_id' => $doe->id, 'checked_on' => $on, 'method' => 'ultrasound', 'result' => $c->ultrasound,
                'fetuses' => $c->fetuses, 'examiner' => $by->name, 'notes' => 'At the health checkup.',
            ]);
        }

        return $mating;
    }

    /** Same rule as the Health page: the goat's health follows its open cases and reports. */
    public static function syncHealth(Animal $animal): void
    {
        $active = HealthCase::where('animal_id', $animal->id)->whereIn('status', HealthCase::ACTIVE)->get();
        $flag = match (true) {
            $active->contains('isolated', true) => 'quarantine',
            $active->whereIn('status', ['open', 'under_treatment'])->isNotEmpty() => 'sick',
            $active->isNotEmpty() => 'recovering',
            HealthRecord::where('animal_id', $animal->id)->where('status', 'open')->exists() => 'monitor',
            default => 'healthy',
        };
        $animal->update(['health_flag' => $flag]);
    }
}
