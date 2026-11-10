<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-180: a shift handover note (append-only). */
class ShiftHandover extends Model
{
    protected $guarded = [];

    protected $casts = ['handed_on' => 'date'];
}
