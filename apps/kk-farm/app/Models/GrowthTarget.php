<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-191: a growth-plan point: target live weight at an age (days), for one breed or every breed. */
class GrowthTarget extends Model
{
    protected $guarded = [];

    protected $casts = ['target_kg' => 'float', 'age_days' => 'integer'];
}
