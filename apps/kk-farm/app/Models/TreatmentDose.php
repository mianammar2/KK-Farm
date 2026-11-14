<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-304: one dose of a prescription: due, given (from stock), missed or held. */
class TreatmentDose extends Model
{
    protected $guarded = [];

    protected $casts = ['due_on' => 'date', 'done_at' => 'datetime', 'adverse' => 'boolean', 'qty' => 'float'];

    public function treatment()
    {
        return $this->belongsTo(Treatment::class);
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
