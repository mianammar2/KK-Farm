<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-221: one age stage of a farm: ERP code, category, age range and expected teeth. */
class AgeStage extends Model
{
    protected $guarded = [];

    protected $casts = ['from_months' => 'float', 'to_months' => 'float', 'sort' => 'integer'];

    public function contains(float $months): bool
    {
        return $months >= $this->from_months && ($this->to_months === null || $months < $this->to_months);
    }
}
