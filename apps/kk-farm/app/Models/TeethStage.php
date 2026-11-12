<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-220 / D-229: one dental code of a farm (MLT, T02 …): classification, local term, approximate age. */
class TeethStage extends Model
{
    protected $guarded = [];

    protected $casts = ['from_months' => 'integer', 'to_months' => 'integer', 'estimate_months' => 'integer', 'sort' => 'integer'];

    /** "T02 · 2 Permanent Incisors (Donda) · about 12–18 months" */
    public function longLabel(): string
    {
        return "{$this->key} · {$this->label}".($this->local_term ? " ({$this->local_term})" : '').' · about '.$this->ageText();
    }

    public function ageText(): string
    {
        return $this->to_months === null ? "{$this->from_months}+ months" : "{$this->from_months}–{$this->to_months} months";
    }
}
