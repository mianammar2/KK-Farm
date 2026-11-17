<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-338: one finished goat count (append-only). */
class GoatCount extends Model
{
    protected $guarded = [];

    protected $casts = ['by_breed' => 'array', 'missing' => 'array', 'extra' => 'array', 'unknown' => 'array'];
}
