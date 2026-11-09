<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mating extends Model
{
    protected $guarded = [];

    protected $casts = ['mated_on' => 'date', 'expected_kidding_on' => 'date', 'check_due_on' => 'date'];

    public const METHODS = ['natural' => 'Natural', 'ai' => 'Artificial insemination', 'pasture' => 'Pasture exposure'];

    /** D-075: the workbook's breeding outcomes (stored value => label). "open" is the workbook's "Pending". */
    public const OUTCOMES = ['open' => 'Pending', 'pregnant' => 'Confirmed pregnant', 'not_pregnant' => 'Not pregnant', 'inconclusive' => 'Inconclusive', 'kidded' => 'Kidded', 'aborted' => 'Aborted', 'closed' => 'Closed'];

    public function checks()
    {
        return $this->hasMany(PregnancyCheck::class)->orderBy('checked_on')->orderBy('id');
    }

    /** CHECK OVERDUE (pending past its check date), RECHECK (inconclusive, next check due), EDD PASSED or KIDDING SOON. */
    /**
     * D-103: every pregnancy has a "Prepare for kidding" task, due when the alert window opens (10 days before the
     * expected kidding by default). It moves when the expected date changes and is cancelled when the pregnancy ends.
     */
    protected static function booted(): void
    {
        static::saved(fn (self $m) => \App\Support\KiddingAlert::sync($m));
    }

    public function alert(int $kiddingAlertDays): ?string
    {
        $today = now()->startOfDay();
        $lastCheck = $this->relationLoaded('checks') ? $this->checks->last() : null;

        return match (true) {
            $this->status === 'open' && $this->check_due_on && $this->check_due_on->lessThan($today) => 'check_overdue',
            $this->status === 'inconclusive' && $lastCheck?->next_check_on && $lastCheck->next_check_on->lessThanOrEqualTo($today->copy()->addDays(7)) => $lastCheck->next_check_on->lessThan($today) ? 'recheck_overdue' : 'recheck_due',
            $this->status === 'pregnant' && $this->expected_kidding_on?->lessThan($today) => 'edd_passed',
            $this->status === 'pregnant' && $this->expected_kidding_on?->lessThanOrEqualTo($today->copy()->addDays(3)) => 'kidding_imminent', // D-103: last 3 days
            $this->status === 'pregnant' && $this->expected_kidding_on?->lessThanOrEqualTo($today->copy()->addDays($kiddingAlertDays)) => 'kidding_soon',
            default => null,
        };
    }

    public function dam()
    {
        return $this->belongsTo(Animal::class, 'dam_id');
    }

    public function sire()
    {
        return $this->belongsTo(Animal::class, 'sire_id');
    }
}
