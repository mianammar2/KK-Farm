<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-189: a hold, isolation or recall on an animal, milk lot, feed or medicine batch. While active it cannot be sold or used. */
class QualityHold extends Model
{
    protected $guarded = [];

    protected $casts = ['placed_at' => 'datetime', 'released_at' => 'datetime'];

    public const KINDS = ['hold' => 'Hold', 'isolate' => 'Isolate', 'recall' => 'Recall'];

    public function qualityCase()
    {
        return $this->belongsTo(QualityCase::class, 'case_id');
    }

    public function scopeActive($q)
    {
        return $q->whereNull('released_at');
    }
}
