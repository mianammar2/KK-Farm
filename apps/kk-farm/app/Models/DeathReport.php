<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-260: the vet's report of a death, approved by the administrator before the goat leaves the farm. */
class DeathReport extends Model
{
    protected $guarded = [];

    protected $casts = ['died_at' => 'datetime', 'decided_at' => 'datetime'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
