<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-198: a lab test of one water point. */
class WaterTest extends Model
{
    protected $guarded = [];

    protected $casts = ['sampled_on' => 'date', 'ph' => 'float', 'nitrate_mg_l' => 'float'];

    public function point()
    {
        return $this->belongsTo(WaterPoint::class, 'water_point_id');
    }
}
