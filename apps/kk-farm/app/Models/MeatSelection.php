<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-197: a goat proposed for the meat business, approved or rejected by someone other than the proposer. */
class MeatSelection extends Model
{
    protected $guarded = [];

    protected $casts = ['decided_at' => 'datetime'];

    public const REASONS = ['cull_breeder' => 'Breeder cull', 'feeder' => 'Feeder (bought or home-grown)', 'slow_grower' => 'Slow grower', 'other' => 'Other'];

    public const STATUS = ['proposed' => 'Waiting for approval', 'approved' => 'Approved for meat', 'rejected' => 'Rejected', 'batched' => 'In a finishing batch'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
