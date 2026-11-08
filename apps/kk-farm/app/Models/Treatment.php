<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-073: one administration of a medicine under a health case. Append-only. */
class Treatment extends Model
{
    protected $guarded = [];

    protected $casts = ['given_on' => 'date', 'next_dose_on' => 'date', 'meat_clear_on' => 'date', 'milk_clear_on' => 'date', 'cost_minor' => 'integer'];

    public const ROUTES = ['oral' => 'Oral', 'injectable' => 'Injectable', 'topical' => 'Topical', 'other' => 'Other'];

    public function healthCase()
    {
        return $this->belongsTo(HealthCase::class);
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
