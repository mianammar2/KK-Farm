<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-075: one weaning decision for a kid (workbook "Weaning"): completed, or postponed to a date. */
class Weaning extends Model
{
    protected $guarded = [];

    protected $casts = ['weaned_on' => 'date', 'postponed_to' => 'date', 'weight_kg' => 'float'];

    public const METHODS = ['gradual' => 'Gradual', 'separate_pen' => 'Separate PEN', 'other' => 'Other'];

    public function kid()
    {
        return $this->belongsTo(Animal::class, 'kid_id');
    }
}
