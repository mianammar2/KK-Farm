<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WeightRecord extends Model
{
    protected $guarded = [];

    protected $casts = ['measured_on' => 'date', 'weight_kg' => 'float', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        // D-208 / BR-018: held and rejected readings never count anywhere (growth, ADG, costing, latest weight).
        static::addGlobalScope('counted', fn (Builder $q) => $q->where(fn ($w) => $w->whereNull('weight_records.review_status')->orWhere('weight_records.review_status', 'accepted')));
        static::creating(function (self $record) {
            if ($record->review_status === null && ($f = \App\Support\WeightCheck::assess($record->animal_id, $record->measured_on ?? now(), (float) $record->weight_kg))) {
                [$record->flag, $record->flag_note] = $f;
                $record->review_status = 'held';
            }
        });
        // D-110: a goat's first weighing estimates its age when nothing else tells it.
        static::created(fn (self $record) => $record->review_status === 'held' ? null : \App\Support\AgeFromWeight::fromRecord($record));
        // D-231: a weighing can make a goat qualified for breeding: it gets its approval task straight away.
        static::created(fn (self $record) => $record->review_status === 'held' ? null : \App\Support\Eligibility::syncOne($record->animal));
        static::created(function (self $record) { // D-232: market weight and other statuses move on with the weighing
            if ($record->review_status !== 'held' && $record->animal) {
                \App\Support\Tenant::flush();
                \App\Support\Lifecycle::record($record->animal);
            }
        });
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function scopeHeld(Builder $q): Builder
    {
        return $q->withoutGlobalScope('counted')->where('review_status', 'held');
    }
}
