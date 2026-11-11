<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-202: seed, fertiliser, chemical, labour, water or fuel put into a crop, with its cost (posted once). */
class FodderInput extends Model
{
    protected $guarded = [];

    protected $casts = ['applied_on' => 'date', 'safe_from' => 'date', 'qty' => 'float'];

    public const KINDS = ['seed' => 'Seed', 'fertiliser' => 'Fertiliser', 'chemical' => 'Pesticide / herbicide', 'labour' => 'Labour', 'water' => 'Irrigation water', 'fuel' => 'Fuel / machinery', 'other' => 'Other'];
}
