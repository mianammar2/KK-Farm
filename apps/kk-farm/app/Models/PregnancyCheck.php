<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Support\Projections;
use Illuminate\Database\Eloquent\Model;

/** A veterinary pregnancy check. The mating's status is derived from these (D-038). */
class PregnancyCheck extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['checked_on' => 'date'];

    protected static function booted(): void
    {
        static::created(fn (self $check) => Projections::refreshMating($check->mating_id));
    }

    public function mating()
    {
        return $this->belongsTo(Mating::class);
    }
}
