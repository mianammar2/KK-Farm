<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-231: breeding thresholds for one sex, for one breed or (breed_id null) every breed. */
class BreedingRule extends Model
{
    protected $guarded = [];

    protected $casts = ['min_age_months' => 'integer', 'min_weight_kg' => 'float', 'mature_weight_kg' => 'float', 'min_mature_pct' => 'integer', 'min_bcs' => 'float', 'weight_max_age_days' => 'integer'];

    public function breed()
    {
        return $this->belongsTo(Breed::class);
    }
}
