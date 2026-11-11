<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-189: an emergency readiness drill. A failed drill opens a quality case. */
class EmergencyDrill extends Model
{
    protected $guarded = [];

    protected $casts = ['held_on' => 'date', 'next_due_on' => 'date', 'passed' => 'boolean'];

    public const SCENARIOS = [
        'fire' => 'Fire', 'outbreak' => 'Disease Outbreak', 'water_failure' => 'Water Failure', 'power_failure' => 'Power Failure', 'flood' => 'Flood / Storm',
        'heat' => 'Heat Wave', 'recall' => 'Product Recall', 'injury' => 'Staff Injury', 'other' => 'Other',
    ];
}
