<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-175: one heat (oestrus) observation of a doe. */
class HeatRecord extends Model
{
    protected $guarded = [];

    protected $casts = ['observed_on' => 'date'];

    public const INTENSITY = ['weak' => 'Weak', 'moderate' => 'Moderate', 'strong' => 'Strong'];

    public const SIGNS = ['Tail flagging', 'Bleating', 'Restless', 'Mounting others', 'Standing to be mounted', 'Swollen red vulva', 'Clear mucus', 'Off feed', 'Seeks the buck'];

    /** Goats come into heat about every 21 days (18–24). */
    public const CYCLE_DAYS = 21;

    public function dam()
    {
        return $this->belongsTo(Animal::class, 'dam_id');
    }
}
