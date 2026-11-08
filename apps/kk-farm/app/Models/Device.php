<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-087: a sensor, camera, scale, gate or meter placed in a shed, pen or open area. */
class Device extends Model
{
    public const STATUSES = ['planned' => 'Planned', 'active' => 'Active', 'offline' => 'Offline', 'retired' => 'Retired'];

    protected $guarded = [];

    protected $casts = ['settings' => 'array', 'last_seen_at' => 'datetime'];

    public function shed()
    {
        return $this->belongsTo(Shed::class);
    }

    public function pen()
    {
        return $this->belongsTo(Pen::class);
    }

    public function keys()
    {
        return $this->hasMany(DeviceKey::class);
    }

    public function readings()
    {
        return $this->hasMany(SensorReading::class);
    }

    public function setting(string $key, float $default): float
    {
        return (float) ($this->settings[$key] ?? $default);
    }

    /** "KKF-BDU-01-P01" or "KKF-BDU-01" or "Not placed". */
    public function placeName(): string
    {
        return $this->pen?->fullName() ?? $this->shed?->name ?? 'Not placed';
    }
}
