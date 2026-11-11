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
        'product_quality' => 'Product Quality (milk, feed, medicine)', 'animal_safety' => 'Animal Safety / Handling', 'financial' => 'Financial Control (cash, stock, records)', // D-205
    ];

    /** D-205: each kind's category for analysis (flow 39 triggers). */
    public const CATEGORY = ['animal_escape' => 'animal_safety', 'predator' => 'animal_safety', 'animal_safety' => 'animal_safety', 'equipment_failure' => 'operations', 'feed_shortage' => 'operations',
        'water_failure' => 'operations', 'fire' => 'operations', 'other' => 'operations', 'theft' => 'financial_control', 'financial' => 'financial_control', 'staff_injury' => 'people_safety', 'product_quality' => 'product_quality'];

    public const CATEGORIES = ['product_quality' => 'Product Quality', 'animal_safety' => 'Animal Safety', 'financial_control' => 'Financial Control', 'operations' => 'Operations', 'people_safety' => 'People Safety'];

    public const SEVERITY = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    public function qualityCase()
    {
        return $this->belongsTo(QualityCase::class);
    }

    public function sop()
    {
        return $this->belongsTo(Sop::class);
    }

    public function pen()
    {
        return $this->belongsTo(Pen::class);
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
