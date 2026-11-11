<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-203: the trip of one sale order, from gate pass to the buyer's receipt. */
class TransportTrip extends Model
{
    protected $guarded = [];

    protected $casts = ['departed_at' => 'datetime', 'delivered_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(SaleOrder::class, 'sale_order_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function checks()
    {
        return $this->hasMany(TransportCheck::class, 'trip_id')->orderBy('checked_at');
    }

    public function claims()
    {
        return $this->hasMany(TransportClaim::class, 'trip_id');
    }
}
