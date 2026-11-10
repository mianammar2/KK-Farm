<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-186: a water, heat, power, airflow or waste issue raised by a round, until resolved and verified. */
class EnvironmentIssue extends Model
{
    protected $guarded = [];

    protected $casts = ['resolved_at' => 'datetime', 'verified_at' => 'datetime'];

    public const KINDS = ['water' => 'No / Unsafe Drinking Water', 'heat' => 'Heat Stress', 'power' => 'Power Failure', 'airflow' => 'Ventilation / Airflow', 'waste' => 'Manure, Drainage or Waste'];

    public function shed()
    {
        return $this->belongsTo(Shed::class);
    }
}
