<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-178 */
class StoreItem extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'reorder_level' => 'float', 'cost_per_unit_minor' => 'integer'];

    public const CATEGORIES = ['bedding' => 'Bedding & Litter', 'cleaning' => 'Cleaning & Disinfection', 'spares' => 'Equipment Spares', 'tools' => 'Tools', 'packaging' => 'Packaging', 'other' => 'Other'];

    public const UNITS = ['kg' => 'KG', 'litre' => 'Litre', 'piece' => 'Piece', 'bag' => 'Bag', 'bale' => 'Bale', 'box' => 'Box', 'roll' => 'Roll'];
}
