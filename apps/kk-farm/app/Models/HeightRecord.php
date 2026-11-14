<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-262: one height measurement of a goat. */
class HeightRecord extends Model
{
    protected $guarded = [];

    protected $casts = ['measured_on' => 'date'];

    public function animal(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }
}
