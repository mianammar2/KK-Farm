<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-178 */
class StoreMove extends Model
{
    protected $guarded = [];

    protected $casts = ['moved_on' => 'date', 'qty' => 'float', 'rate_minor' => 'integer'];

    public const TYPES = ['opening' => ['Opening stock', 1], 'purchase' => ['Purchase', 1], 'receipt' => ['Receipt (no purchase)', 1], 'issue' => ['Issued for use', -1], 'adjustment' => ['Adjustment after count', 0]];

    public function item()
    {
        return $this->belongsTo(StoreItem::class, 'store_item_id');
    }
}
