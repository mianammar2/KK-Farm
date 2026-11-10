<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-180: an entry in the farm incident register. */
class Incident extends Model
{
    protected $guarded = [];

    protected $casts = ['occurred_at' => 'datetime', 'resolved_at' => 'datetime'];

    public const KINDS = [
        'animal_escape' => 'Animal Escape', 'equipment_failure' => 'Equipment Failure', 'feed_shortage' => 'Feed Shortage', 'water_failure' => 'Water or Power Failure',
        'predator' => 'Predator / Dog Attack', 'theft' => 'Theft', 'staff_injury' => 'Staff Injury', 'fire' => 'Fire', 'other' => 'Other Incident',
    ];

    public const SEVERITY = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    public function pen()
    {
        return $this->belongsTo(Pen::class);
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
