<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-054: a versioned ration for one feeding group. Approved plans are never edited; a new plan replaces them from its date. */
class RationPlan extends Model
{
    protected $guarded = [];

    protected $casts = ['effective_from' => 'date', 'approved_at' => 'datetime'];

    public function lines()
    {
        return $this->hasMany(RationPlanLine::class);
    }

    public function group()
    {
        return $this->belongsTo(FeedingGroup::class, 'feeding_group_id');
    }

    public function split(string $meal): int
    {
        return (int) $this->{"split_$meal"};
    }
}
