<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-195: a customer's milk order MO-YYMM-NNN, filled from Released lots (V3 journey F). */
class MilkOrder extends Model
{
    protected $guarded = [];

    protected $casts = ['litres' => 'float', 'wanted_on' => 'date'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function dispatches()
    {
        return $this->hasMany(MilkDispatch::class, 'order_id');
    }

    public function remaining(): float
    {
        return round($this->litres - (float) $this->dispatches()->sum('litres'), 2);
    }
}
