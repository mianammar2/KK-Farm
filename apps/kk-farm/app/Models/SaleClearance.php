<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-232: a sale clearance (or its withdrawal) for one goat, with the checks at that moment. Append-only. */
class SaleClearance extends Model
{
    protected $guarded = [];

    protected $casts = ['checks' => 'array', 'decided_on' => 'date'];
}
