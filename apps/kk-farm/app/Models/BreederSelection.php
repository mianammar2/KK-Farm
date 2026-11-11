<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-191: a replacement-breeder / sale-stock decision with the eligibility checks at the time (append-only). */
class BreederSelection extends Model
{
    protected $guarded = [];

    protected $casts = ['checks' => 'array', 'override' => 'boolean', 'decided_on' => 'date'];

    public const DECISIONS = ['replacement' => 'Replacement Breeder', 'sale' => 'Sale Stock', 'keep' => 'Keep Growing'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
