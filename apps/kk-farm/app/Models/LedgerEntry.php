<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $guarded = [];

    protected $casts = ['occurred_on' => 'date', 'amount' => 'float'];

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
