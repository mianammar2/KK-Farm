<?php

namespace App\Support;

use App\Models\Kidding;
use App\Models\Mating;
use App\Models\User;
use Illuminate\Support\Carbon;

/** D-166: a pregnancy loss confirmed on examination is recorded as a pregnancy outcome (same path as the outcome form). */
final class PregnancyLoss
{
    public static function record(Mating $mating, Carbon $on, string $outcome, User $by, ?string $diagnosis = null): Kidding
    {
        $dam = $mating->dam;
        $k = Kidding::create([
            'dam_id' => $dam->id, 'mating_id' => $mating->id, 'kidded_on' => $on, 'born_alive' => 0, 'stillborn' => 0, 'outcome' => $outcome,
            'delivery_method' => 'NAP', 'mother_status' => 'PAR', 'gestation_days' => (int) $mating->mated_on->diffInDays($on), 'assisted' => 'no',
            'attendant' => $by->name, 'recorded_by' => $by->name, 'diagnosis' => $diagnosis ?? 'Confirmed pregnancy loss on ultrasound', 'vet_officer' => $by->name,
        ]);
        $mating->update(['status' => 'aborted']);
        $dam->update([
            'repro_status' => 'open', 'repro_state' => null,
            'monitor_until' => $on->copy()->addDays(Kidding::MONITOR_DAYS)->toDateString(), 'monitor_reason' => $outcome,
        ]);
        Farm::event($dam, 'breeding', Kidding::OUTCOMES[$outcome].' (confirmed on ultrasound)', $by->name, $on);
        if ($dam->status === 'active') {
            Maternity::admit($k, $dam->fresh(), $by);
        }
        PregnancyAlerts::raise($k, $dam->fresh());

        return $k;
    }
}
