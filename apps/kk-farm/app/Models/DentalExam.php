<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-229: one teeth examination of a goat. Append-only: a new examination is a new row, never an edit. */
class DentalExam extends Model
{
    protected $guarded = [];

    protected $casts = ['examined_on' => 'date'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
