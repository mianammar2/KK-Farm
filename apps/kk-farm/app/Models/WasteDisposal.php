<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-204: one disposal with its evidence, infection controls and cost. */
class WasteDisposal extends Model
{
    protected $guarded = [];

    protected $casts = ['disposed_on' => 'date', 'verified_at' => 'datetime', 'ppe_used' => 'boolean', 'area_disinfected' => 'boolean', 'qty' => 'float'];

    public function stream()
    {
        return $this->belongsTo(WasteStream::class, 'stream_id');
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
