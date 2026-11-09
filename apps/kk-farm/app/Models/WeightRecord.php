<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeightRecord extends Model
{
    protected $guarded = [];

    protected $casts = ['measured_on' => 'date', 'weight_kg' => 'float'];

    /** D-110: a goat's first weighing estimates its age when nothing else tells it. */
    protected static function booted(): void
    {
        static::created(fn (self $record) => \App\Support\AgeFromWeight::fromRecord($record));
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
