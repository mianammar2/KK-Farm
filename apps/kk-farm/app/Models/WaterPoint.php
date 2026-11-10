<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-186: a drinking-water point or tank. */
class WaterPoint extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public const KINDS = ['trough' => 'Trough', 'nipple' => 'Nipple Drinker', 'bowl' => 'Water Bowl', 'tank' => 'Storage Tank', 'borehole' => 'Borehole / Well', 'pipeline' => 'Pipeline'];

    public function shed()
    {
        return $this->belongsTo(Shed::class);
    }

    public function pen()
    {
        return $this->belongsTo(Pen::class);
    }
}
