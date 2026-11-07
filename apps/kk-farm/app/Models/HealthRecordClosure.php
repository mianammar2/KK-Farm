<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Support\Projections;
use Illuminate\Database\Eloquent\Model;

/** A veterinarian closing a health case. The record's open/closed status is derived from these (D-038). */
class HealthRecordClosure extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['closed_on' => 'date'];

    protected static function booted(): void
    {
        static::created(fn (self $closure) => Projections::refreshHealthRecord($closure->health_record_id));
    }

    public function record()
    {
        return $this->belongsTo(HealthRecord::class, 'health_record_id');
    }
}
