<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-177: one mixing of a TMR formula. Its stock movements take the ingredients out and put the TMR in. */
class TmrBatch extends Model
{
    protected $guarded = [];

    protected $casts = ['mixed_on' => 'date', 'total_kg' => 'float', 'cost_minor' => 'integer'];

    public function formula()
    {
        return $this->belongsTo(TmrFormula::class, 'tmr_formula_id');
    }
}
