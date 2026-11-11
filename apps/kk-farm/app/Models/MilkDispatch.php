<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-188: milk from a released lot sent to a buyer or issued to processing. */
class MilkDispatch extends Model
{
    protected $guarded = [];

    protected $casts = ['dispatched_on' => 'date', 'litres' => 'float', 'dispatch_temp_c' => 'float', 'container_ok' => 'boolean', 'vehicle_ok' => 'boolean', 'due_on' => 'date', 'collected_at' => 'datetime'];

    public function lot()
    {
        return $this->belongsTo(MilkLot::class, 'lot_id');
    }

    public function order()
    {
        return $this->belongsTo(MilkOrder::class, 'order_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
