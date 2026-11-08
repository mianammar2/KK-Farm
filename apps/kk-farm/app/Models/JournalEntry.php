<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-083: a balanced, append-only journal entry. Postgres refuses edits, deletes and unbalanced lines. */
class JournalEntry extends Model
{
    public const UPDATED_AT = null;

    public const SOURCES = ['expense' => 'Expense', 'income' => 'Income', 'sale' => 'Sale', 'payment' => 'Payment', 'reversal' => 'Reversal', 'manual' => 'Manual journal', 'opening' => 'Opening balance'];

    protected $guarded = [];

    protected $casts = ['posted_on' => 'date'];

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function ledgerEntry()
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reverses_id');
    }
}
