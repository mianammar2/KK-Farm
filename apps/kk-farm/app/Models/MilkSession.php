<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-188: a closed AM / PM milking session and its allocation. */
class MilkSession extends Model
{
    protected $guarded = [];

    protected $casts = ['milked_on' => 'date', 'hygiene_ok' => 'boolean', 'equipment_ok' => 'boolean', 'produced_l' => 'float', 'held_l' => 'float', 'colostrum_l' => 'float', 'kid_l' => 'float', 'discard_l' => 'float', 'saleable_l' => 'float'];

    public function lot()
    {
        return $this->hasOne(MilkLot::class, 'session_id');
    }
}
