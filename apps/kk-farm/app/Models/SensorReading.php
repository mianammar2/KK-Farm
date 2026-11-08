<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-087: one raw, append-only reading from a device. */
class SensorReading extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['read_at' => 'datetime', 'payload' => 'array', 'value' => 'float'];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
