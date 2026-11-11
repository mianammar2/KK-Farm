<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-194: one scan at loading (match, duplicate, not on the order, unknown tag). */
class SaleScan extends Model
{
    protected $guarded = [];
}
