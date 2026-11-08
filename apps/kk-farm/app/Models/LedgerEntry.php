<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $guarded = [];

    protected $casts = ['occurred_on' => 'date', 'amount' => 'float', 'fee' => 'float', 'paid_now' => 'float'];

    /** D-083: every ledger entry posts its balanced journal in the same transaction (a reversal posts the mirror image). */
    protected static function booted(): void
    {
        static::created(fn (self $e) => \App\Support\Accounting::postLedger($e));
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function journals()
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function shed()
    {
        return $this->belongsTo(Shed::class);
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function reversal()
    {
        return $this->hasOne(LedgerEntry::class, 'reverses_id');
    }
}
