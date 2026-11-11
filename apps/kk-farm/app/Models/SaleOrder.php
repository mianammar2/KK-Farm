<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-194: a sale order SO-YYMM-NNN (V3 journey E): quote → approved and reserved → loaded by scan → dispatched and invoiced → collected. */
class SaleOrder extends Model
{
    protected $guarded = [];

    protected $casts = ['approved_at' => 'datetime', 'dispatched_on' => 'date', 'due_on' => 'date', 'collected_at' => 'datetime', 'checklist' => 'array'];

    public const STATUS = ['quote' => 'Quote: needs approval', 'reserved' => 'Approved & Reserved', 'dispatched' => 'Dispatched & Invoiced', 'cancelled' => 'Cancelled'];

    /** The dispatch checklist, ticked before loading. */
    public const CHECKLIST = ['health' => 'Vet health check done, no withdrawal', 'documents' => 'Sale and movement papers ready', 'transport' => 'Transport clean and arranged', 'water' => 'Goats watered, not fed just before loading', 'buyer' => 'Buyer or agent present to receive'];

    public function lines()
    {
        return $this->hasMany(SaleOrderLine::class, 'order_id')->orderBy('id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function trip()
    {
        return $this->hasOne(TransportTrip::class, 'sale_order_id');
    }

    public function scans()
    {
        return $this->hasMany(SaleScan::class, 'order_id');
    }
}
