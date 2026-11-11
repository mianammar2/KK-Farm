<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-194: one goat on a sale order. */
class SaleOrderLine extends Model
{
    protected $guarded = [];

    protected $casts = ['weight_kg' => 'float', 'loaded_at' => 'datetime', 'cost' => 'array'];

    public const OPEN = ['quoted', 'reserved', 'loaded'];

    public function order()
    {
        return $this->belongsTo(SaleOrder::class, 'order_id');
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
