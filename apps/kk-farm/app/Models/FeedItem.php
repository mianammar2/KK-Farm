<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-054: a feed the farm stocks (workbook "Feed Items"). Stock is calculated, never stored. */
class FeedItem extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'reorder_level_kg' => 'float', 'cost_per_kg_minor' => 'integer'];

    public const CATEGORIES = ['roughage' => 'Roughage', 'concentrate' => 'Concentrate', 'tmr' => 'TMR', 'mineral' => 'Mineral', 'milk_replacer' => 'Milk Replacer', 'supplement' => 'Supplement', 'other' => 'Other'];
}
