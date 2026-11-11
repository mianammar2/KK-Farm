<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-203: a vehicle for moving goats; used only once inspected and approved. */
class Vehicle extends Model
{
    protected $guarded = [];

    protected $casts = ['inspected_on' => 'date', 'inspection' => 'array', 'approved' => 'boolean', 'active' => 'boolean'];

    public const KINDS = ['pickup' => 'Pickup', 'truck' => 'Truck', 'trailer' => 'Trailer', 'other' => 'Other'];

    /** The inspection points; all must pass for approval. */
    public const POINTS = ['clean' => 'Cleaned and disinfected', 'ventilation' => 'Ventilated, shaded', 'floor' => 'Non-slip floor, bedding', 'partitions' => 'Partitions / no sharp edges', 'ramp' => 'Safe loading ramp', 'water' => 'Water for long trips'];

    public const VALID_DAYS = 90; // an inspection is good for 90 days

    public function usable(): bool
    {
        return $this->approved && $this->active && $this->inspected_on && $this->inspected_on->gte(now()->subDays(self::VALID_DAYS)->startOfDay());
    }
}
