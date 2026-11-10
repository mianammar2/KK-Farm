<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-178 */
class PurchaseOrder extends Model
{
    protected $guarded = [];

    protected $casts = ['ordered_on' => 'date', 'expected_on' => 'date', 'received_on' => 'date'];

    public const STATUSES = ['draft' => 'Draft', 'ordered' => 'Ordered', 'received' => 'Received', 'cancelled' => 'Cancelled'];

    public function lines()
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Total in minor units: quantity × rate over all lines. */
    public function totalMinor(): int
    {
        return (int) round($this->lines->sum(fn ($l) => $l->qty * $l->rate_minor));
    }
}
