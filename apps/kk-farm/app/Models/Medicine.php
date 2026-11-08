<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-074: a medicine the farm stocks (workbook "Medicine Stock"). Quantity is calculated from movements. */
class Medicine extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'reorder_level' => 'float', 'cost_per_unit_minor' => 'integer'];

    public const CATEGORIES = [
        'antibiotic' => 'Antibiotic', 'dewormer' => 'Dewormer (anthelmintic)', 'vaccine' => 'Vaccine', 'anti_inflammatory' => 'Anti-inflammatory / pain',
        'vitamin' => 'Vitamin / mineral', 'antiseptic' => 'Antiseptic / wound care', 'other' => 'Other',
    ];

    public const UNITS = ['ml' => 'ml', 'tablet' => 'tablet', 'bolus' => 'bolus', 'dose' => 'dose', 'vial' => 'vial', 'g' => 'g', 'sachet' => 'sachet'];

    public function movements()
    {
        return $this->hasMany(MedicineMovement::class);
    }
}
