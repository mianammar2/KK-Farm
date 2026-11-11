<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-190: a purchased market / breeder lot APL-YYYY-NNNN (V3 journey A, Purchase-to-Quarantine). */
class AnimalPurchaseLot extends Model
{
    protected $guarded = [];

    protected $casts = ['approved_at' => 'datetime', 'received_on' => 'date'];

    public const STATUS = ['requested' => 'Waiting for Approval', 'approved' => 'Approved: Buy & Receive', 'rejected' => 'Not Approved', 'received' => 'Received', 'cancelled' => 'Cancelled'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(AnimalPurchaseItem::class, 'lot_id')->orderBy('id');
    }

    public function animals()
    {
        return $this->hasMany(Animal::class, 'purchase_lot_id');
    }

    public function quarantinePen()
    {
        return $this->belongsTo(Pen::class, 'quarantine_pen_id');
    }
}
