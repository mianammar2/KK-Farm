<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-076: an animal or product sale (workbook "Sales"). Net = gross − fee/discount. */
class Sale extends Model
{
    protected $guarded = [];

    protected $casts = ['sold_on' => 'date', 'gross_minor' => 'integer', 'fee_minor' => 'integer'];

    public const CATEGORIES = ['live_goat' => 'Live goat', 'breeding_goat' => 'Breeding goat', 'product' => 'Goat product (milk, manure…)', 'other' => 'Other'];

    public function netMinor(): int
    {
        return $this->gross_minor - $this->fee_minor;
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class); // D-178
    }
}
