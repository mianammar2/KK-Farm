<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-087: something a sensor noticed that a person must look at. It recommends; people decide. */
class SensorAlert extends Model
{
    public const KINDS = [
        'weight_loss' => 'Weight loss', 'headcount' => 'Headcount mismatch', 'environment' => 'Air or temperature', 'out_too_long' => 'Out too long',
        'unknown_tag' => 'Unknown tag', 'device_offline' => 'Device offline',
    ];

    protected $guarded = [];

    protected $casts = ['resolved_at' => 'datetime'];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function pen()
    {
        return $this->belongsTo(Pen::class);
    }
}
