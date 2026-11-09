<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-117: one lactation of a doe, from kidding (or arrival in milk) to drying off. */
class Lactation extends Model
{
    protected $guarded = [];

    protected $casts = ['started_on' => 'date', 'dried_on' => 'date'];

    public const DRY_REASONS = ['end' => 'End of lactation', 'low_yield' => 'Low milk yield', 'pregnant' => 'Pregnant again (rest before kidding)', 'health' => 'Health or treatment', 'kids_weaned' => 'Kids weaned', 'other' => 'Other'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function scopeOpen($q)
    {
        return $q->whereNull('dried_on');
    }

    public function daysInMilk(): int
    {
        return (int) $this->started_on->diffInDays(($this->dried_on ?? now())->copy()->startOfDay());
    }
}
