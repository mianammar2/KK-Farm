<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-087: a goat going out to an open area or coming back in. Its home pen does not change. */
class AnimalPresence extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'animal_presence';

    protected $guarded = [];

    protected $casts = ['at' => 'datetime'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function area()
    {
        return $this->belongsTo(Pen::class, 'area_id');
    }
}
