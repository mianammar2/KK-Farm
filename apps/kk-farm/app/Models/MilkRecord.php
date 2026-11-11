<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** D-117: milk from one doe at one session. Append-only: a correction is a new row that supersedes the old one. */
class MilkRecord extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['milked_on' => 'date', 'litres' => 'float', 'discarded' => 'boolean'];

    public const SESSIONS = ['morning' => 'Morning', 'midday' => 'Midday', 'evening' => 'Evening'];

    /** Rows not replaced by a correction. */
    public function scopeCurrent($q)
    {
        return $q->whereNotIn('id', fn ($s) => $s->select('supersedes_id')->from('milk_records')->whereNotNull('supersedes_id'));
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function lactation()
    {
        return $this->belongsTo(Lactation::class);
    }
}
