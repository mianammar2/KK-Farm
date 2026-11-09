<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-113: a live-weight market rate, PKR per KG. Append-only: a correction is a newer row for the same day. */
class MarketRate extends Model
{
    protected $guarded = [];

    protected $casts = ['rate_on' => 'date', 'rate_minor_per_kg' => 'integer'];
}
