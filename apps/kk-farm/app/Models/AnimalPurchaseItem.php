<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-190: one goat counted and inspected on receipt of a purchase lot. */
class AnimalPurchaseItem extends Model
{
    protected $guarded = [];

    protected $casts = ['weight_kg' => 'float'];

    public const SCREENING = ['ok' => 'Healthy: accept', 'sick' => 'Sick: accept, vet case', 'rejected' => 'Reject: return to seller'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function breed()
    {
        return $this->belongsTo(Breed::class);
    }
}
