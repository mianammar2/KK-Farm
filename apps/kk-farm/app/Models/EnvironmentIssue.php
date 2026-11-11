<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-186: a water, heat, power, airflow or waste issue raised by a round, until resolved and verified. */
class EnvironmentIssue extends Model
{
    protected $guarded = [];

    protected $casts = ['resolved_at' => 'datetime', 'verified_at' => 'datetime', 'started_at' => 'datetime', 'supply_at' => 'datetime', 'supply_approved_at' => 'datetime',
        'restored_at' => 'datetime', 'health_checked_at' => 'datetime', 'point_ids' => 'array'];

    /** D-198 */
    public const PROBLEMS = ['interruption' => 'Interruption (no water)', 'shortage' => 'Shortage (not enough)', 'contamination' => 'Contamination (unsafe)', 'inspection' => 'Failed inspection'];

    public const SOURCES = ['tanker' => 'Water tanker', 'borehole' => 'Borehole / well', 'stored' => 'Stored tank', 'other' => 'Other'];

    public const KINDS = ['water' => 'No / Unsafe Drinking Water', 'heat' => 'Heat Stress', 'power' => 'Power Failure', 'airflow' => 'Ventilation / Airflow', 'waste' => 'Manure, Drainage or Waste'];

    public function welfareCase()
    {
        return $this->belongsTo(WelfareCase::class);
    }

    public function jobs()
    {
        return $this->hasMany(MaintenanceJob::class, 'environment_issue_id');
    }

    public function shed()
    {
        return $this->belongsTo(Shed::class);
    }
}
