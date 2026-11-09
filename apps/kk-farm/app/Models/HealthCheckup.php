<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-095: one routine (or any-time) health checkup of one goat. */
class HealthCheckup extends Model
{
    public const ULTRASOUND = ['pregnant' => 'Pregnant', 'not_pregnant' => 'Not pregnant', 'inconclusive' => 'Not clear'];

    public const OUTCOMES = ['healthy' => 'Healthy', 'monitor' => 'Watch', 'sick' => 'Sick'];

    /** Normal ranges for an adult goat, to flag readings (the vet decides). */
    public const NORMAL = ['temperature_f' => [101.3, 103.5], 'heart_rate' => [70, 90], 'respiration_rate' => [15, 30]];

    protected $guarded = [];

    protected $casts = ['checked_on' => 'date', 'temperature_f' => 'float', 'weight_kg' => 'float', 'months_pregnant' => 'float'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function healthCase()
    {
        return $this->belongsTo(HealthCase::class);
    }

    /** @return list<string> readings outside normal, e.g. "Temperature 104.2 °F (high)" */
    public function flags(): array
    {
        $out = [];
        foreach (['temperature_f' => ['Temperature', '°F'], 'heart_rate' => ['Heart rate', '/min'], 'respiration_rate' => ['Breathing', '/min']] as $key => [$label, $unit]) {
            $v = $this->{$key};
            if ($v === null) {
                continue;
            }
            [$low, $high] = self::NORMAL[$key];
            if ($v > $high || $v < $low) {
                $out[] = "$label ".rtrim(rtrim(number_format((float) $v, 1), '0'), '.')." $unit (".($v > $high ? 'high' : 'low').')';
            }
        }

        return $out;
    }
}
