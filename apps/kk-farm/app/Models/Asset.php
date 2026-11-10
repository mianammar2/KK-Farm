<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-182: a farm asset (machinery, equipment, vehicle, tool, infrastructure). */
class Asset extends Model
{
    protected $guarded = [];

    protected $casts = ['purchased_on' => 'date', 'next_service_on' => 'date'];

    public const CATEGORIES = [
        'machinery' => 'Farm Machinery', 'feed_mixer' => 'Feed Mixer', 'scale' => 'Weighing Scale', 'rfid_reader' => 'RFID Reader', 'water_pump' => 'Water Pump',
        'ventilation' => 'Fans & Ventilation', 'vehicle' => 'Vehicle', 'tool' => 'Tool', 'infrastructure' => 'Shed Infrastructure', 'generator' => 'Generator / Solar', 'other' => 'Other',
    ];

    public const STATUS = ['working' => 'Working', 'under_repair' => 'Under Repair', 'retired' => 'Retired'];

    public function shed()
    {
        return $this->belongsTo(Shed::class);
    }

    public function jobs()
    {
        return $this->hasMany(MaintenanceJob::class)->orderByDesc('requested_on')->orderByDesc('id');
    }
}
