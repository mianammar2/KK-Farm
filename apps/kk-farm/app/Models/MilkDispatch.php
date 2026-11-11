<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-188: milk from a released lot sent to a buyer or issued to processing. */
class MilkDispatch extends Model
{
    protected $guarded = [];

    protected $casts = ['dispatched_on' => 'date', 'litres' => 'float'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
