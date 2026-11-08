<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedingGroup extends Model
{
    protected $guarded = [];

    protected $casts = ['ration' => 'array', 'approved_at' => 'datetime'];

    public function plans()
    {
        return $this->hasMany(RationPlan::class);
    }

    public function animals()
    {
        return $this->hasMany(Animal::class);
    }
}
