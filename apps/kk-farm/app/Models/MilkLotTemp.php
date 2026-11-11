<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-196: one storage temperature reading of a milk lot (append-only). */
class MilkLotTemp extends Model
{
    protected $guarded = [];

    protected $casts = ['temp_c' => 'float', 'excursion' => 'boolean'];
}
