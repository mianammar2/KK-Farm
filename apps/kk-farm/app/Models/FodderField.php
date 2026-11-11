<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-202: a field or pasture plot (App 22). */
class FodderField extends Model
{
    protected $guarded = [];

    protected $casts = ['area_acres' => 'float', 'active' => 'boolean'];

    public const IRRIGATION = ['canal' => 'Canal', 'tubewell' => 'Tubewell', 'rain' => 'Rain-fed', 'drip' => 'Drip / sprinkler'];

    public function crops()
    {
        return $this->hasMany(FodderCrop::class, 'field_id');
    }
}
