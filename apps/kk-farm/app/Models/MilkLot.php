<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-188: a saleable milk lot MLK-YYMMDD-NNN, traced back to its session and does and forward to its buyers. */
class MilkLot extends Model
{
    protected $guarded = [];

    protected $casts = ['litres' => 'float', 'storage_temp_c' => 'float', 'appearance_ok' => 'boolean', 'smell_ok' => 'boolean', 'tested_at' => 'datetime', 'released_at' => 'datetime'];

    public const STATUS = ['pending_qc' => 'Pending QC', 'stored' => 'Chilled / Stored', 'released' => 'Released', 'hold' => 'Hold', 'rejected' => 'Rejected', 'dispatched' => 'Dispatched', 'recalled' => 'Recalled'];

    public function session()
    {
        return $this->belongsTo(MilkSession::class, 'session_id');
    }

    public function dispatches()
    {
        return $this->hasMany(MilkDispatch::class, 'lot_id');
    }

    public function remaining(): float
    {
        return round($this->litres - (float) $this->dispatches()->sum('litres'), 2);
    }
}
