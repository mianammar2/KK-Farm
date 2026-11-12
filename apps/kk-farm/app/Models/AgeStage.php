<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/** D-221 / D-229: one age stage of a farm: ERP code, age category, the age it starts at, and what it counts as. */
class AgeStage extends Model
{
    protected $guarded = [];

    protected $casts = ['starts_at' => 'integer', 'sort' => 'integer'];

    private ?AgeStage $next = null;

    public function setNext(?AgeStage $next): void
    {
        $this->next = $next;
    }

    public function next(): ?AgeStage
    {
        return $this->next;
    }

    /** The day a goat born on $birth enters this stage (calendar months, not 30-day blocks). */
    public function startDate(CarbonInterface $birth): CarbonInterface
    {
        $d = $birth->copy();

        return $this->starts_unit === 'months' ? $d->addMonthsNoOverflow($this->starts_at) : $d->addDays($this->starts_at);
    }

    public function startDays(): float
    {
        return $this->starts_unit === 'months' ? $this->starts_at * 30.4375 : (float) $this->starts_at;
    }

    public function startMonths(): float
    {
        return $this->startDays() / 30.4375;
    }

    /** Middle of the stage in months, to estimate a bought goat's birth date (open-ended stage: start + 6). */
    public function estimateMonths(): float
    {
        return $this->next ? ($this->startMonths() + $this->next->startMonths()) / 2 : $this->startMonths() + 6;
    }

    /** "0–14 days", "60 days to under 14 months", "48 months and above" */
    public function rangeText(): string
    {
        $from = $this->starts_at.' '.$this->starts_unit;
        if (! $this->next) {
            return "{$from} and above";
        }
        if ($this->next->starts_unit === $this->starts_unit) {
            return $this->starts_unit === 'days' ? "{$this->starts_at}–".($this->next->starts_at - 1).' days' : "{$this->starts_at} to under {$this->next->starts_at} months";
        }

        return "{$from} to under {$this->next->starts_at} {$this->next->starts_unit}";
    }
}
