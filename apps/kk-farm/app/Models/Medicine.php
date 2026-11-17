<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-074: a medicine the farm stocks (workbook "Medicine Stock"). Quantity is calculated from movements. */
class Medicine extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'reorder_level' => 'float', 'cost_per_unit_minor' => 'integer', 'pack_size' => 'float'];

    public const CATEGORIES = [
        'antibiotic' => 'Antibiotic', 'dewormer' => 'Dewormer (anthelmintic)', 'vaccine' => 'Vaccine', 'anti_inflammatory' => 'Anti-inflammatory / pain',
        'vitamin' => 'Vitamin / mineral', 'antiseptic' => 'Antiseptic / wound care',
        // D-332
        'steroid' => 'Corticosteroid', 'antihistamine' => 'Antihistamine', 'supportive' => 'Supportive / tonic', 'fluids' => 'Fluids / infusion',
        'digestive' => 'Digestive / anti-diarrhoeal', 'ectoparasiticide' => 'External parasite control', 'anaesthetic' => 'Local anaesthetic',
        'emergency' => 'Emergency / antidote', 'consumable' => 'Vet consumables', 'equipment' => 'Vet equipment',
        'other' => 'Other',
    ];

    public const UNITS = ['ml' => 'ml', 'tablet' => 'tablet', 'bolus' => 'bolus', 'dose' => 'dose', 'vial' => 'vial', 'g' => 'g', 'sachet' => 'sachet', 'piece' => 'piece', 'tube' => 'tube'];

    /** D-332: how the product is bought; one pack holds pack_size stock units (a vial of 100 ml). */
    public const PACKS = ['vial' => 'Vial', 'ampoule' => 'Ampoule', 'bottle' => 'Bottle', 'can' => 'Can', 'sachet' => 'Sachet', 'box' => 'Box', 'piece' => 'Piece', 'tube' => 'Tube', 'pack' => 'Pack'];

    /** D-332: the veterinarian's check of the product. A held product cannot be given (MedicineStock::issue). */
    public const CHECKS = ['confirmed' => 'Confirmed', 'to_confirm' => 'To confirm', 'hold' => 'On hold'];

    /** D-333: the diseases this medicine is set for. */
    public function diseases()
    {
        return $this->belongsToMany(Disease::class, 'disease_medicine');
    }

    public function movements()
    {
        return $this->hasMany(MedicineMovement::class);
    }
}
