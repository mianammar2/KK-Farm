<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-187: one goat in a finishing batch, from entry to exit. */
class FatteningBatchAnimal extends Model
{
    protected $guarded = [];

    protected $casts = ['joined_on' => 'date', 'left_on' => 'date', 'cleared_at' => 'datetime', 'entry_weight_kg' => 'float', 'exit_weight_kg' => 'float'];

    public const STATUS = ['finishing' => 'Finishing', 'ready' => 'Sale Ready', 'reserved' => 'Reserved', 'sold' => 'Sold', 'died' => 'Died', 'removed' => 'Removed'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function batch()
    {
        return $this->belongsTo(FatteningBatch::class, 'batch_id');
    }
}
