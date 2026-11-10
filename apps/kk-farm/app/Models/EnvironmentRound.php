<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-186: one environment round of a shed (water, climate, ventilation, waste, utility cost). */
class EnvironmentRound extends Model
{
    protected $guarded = [];

    protected $casts = ['round_at' => 'datetime', 'water_ok' => 'boolean', 'ventilation_ok' => 'boolean', 'power_ok' => 'boolean', 'climate_risk' => 'boolean', 'waste_ok' => 'boolean', 'temperature_c' => 'float', 'thi' => 'float'];

    public const UTILITIES = ['electricity' => 'Electricity', 'water' => 'Water', 'fuel' => 'Generator Fuel', 'solar' => 'Solar Maintenance'];

    /** Temperature-humidity index from °C and relative humidity (%). */
    public static function thi(float $c, int $rh): float
    {
        $f = 1.8 * $c + 32;

        return round($f - (0.55 - 0.0055 * $rh) * (1.8 * $c - 26), 1);
    }

    public function shed()
    {
        return $this->belongsTo(Shed::class);
    }

    public function waterChecks()
    {
        return $this->hasMany(WaterCheck::class, 'round_id');
    }
}
