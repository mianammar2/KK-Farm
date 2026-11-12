<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-231: an approval or rejection of a goat for breeding, with the checks at that moment. Append-only. */
class BreedingApproval extends Model
{
    protected $guarded = [];

    protected $casts = ['checks' => 'array', 'repro_exam_passed' => 'boolean', 'genetics_approved' => 'boolean', 'override' => 'boolean', 'decided_on' => 'date'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
