<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-203: a customer claim after delivery (death, injury, shortage). */
class TransportClaim extends Model
{
    protected $guarded = [];

    protected $casts = ['decided_at' => 'datetime'];

    public function trip()
    {
        return $this->belongsTo(TransportTrip::class, 'trip_id');
    }

    public const KINDS = ['death' => 'Death in transit', 'injury' => 'Injured on arrival', 'shortage' => 'Fewer goats received', 'other' => 'Other'];
}
