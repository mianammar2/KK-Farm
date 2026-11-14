<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-292: an observation the vet ticks on a case (Symptom Master); linked to the diseases it is associated with. */
class Symptom extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function diseases()
    {
        return $this->belongsToMany(Disease::class, 'disease_symptom');
    }
}
