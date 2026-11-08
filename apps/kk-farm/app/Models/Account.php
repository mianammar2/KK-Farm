<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-083: one account in the farm's chart of accounts. */
class Account extends Model
{
    public const TYPES = ['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expense'];

    /** Assets and expenses grow with a debit; liabilities, equity and income with a credit. */
    public const DEBIT_NORMAL = ['asset', 'expense'];

    protected $guarded = [];

    protected $casts = ['is_money' => 'boolean', 'active' => 'boolean'];

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function debitNormal(): bool
    {
        return in_array($this->type, self::DEBIT_NORMAL, true);
    }

    public function label(): string
    {
        return "{$this->code} {$this->name}";
    }
}
