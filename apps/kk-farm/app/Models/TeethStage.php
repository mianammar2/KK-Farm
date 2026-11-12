<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-220: one teeth stage of a farm (milk teeth, 2 teeth ...). */
class TeethStage extends Model
{
    protected $guarded = [];

    protected $casts = ['from_months' => 'integer', 'to_months' => 'integer', 'estimate_months' => 'integer', 'sort' => 'integer'];

    /** "Milk teeth (0–12 months)" */
    public function longLabel(): string
    {
        return "{$this->label} (".($this->to_months === null ? "{$this->from_months}+ months" : "{$this->from_months}–{$this->to_months} months").')';
    }
}
