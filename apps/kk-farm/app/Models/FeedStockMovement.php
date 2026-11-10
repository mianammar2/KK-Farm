<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-054: append-only stock receipts and adjustments (workbook "Stock Movements"). Feeding is never entered here. */
class FeedStockMovement extends Model
{
    protected $guarded = [];

    protected $casts = ['moved_on' => 'date', 'qty_kg' => 'float', 'rate_minor' => 'integer'];

    /** type => [label, direction]: +1 in, -1 out, 0 chosen by the user. */
    public const TYPES = [
        'opening' => ['Opening stock', 1], 'purchase' => ['Purchase', 1], 'receipt' => ['Receipt (no purchase)', 1], 'return' => ['Return to supplier', -1],
        'write_off' => ['Write-off (spoiled, lost)', -1], 'adjustment' => ['Adjustment after count', 0], 'other' => ['Other', 0],
        'mix_in' => ['TMR mixed', 1], 'mix_out' => ['Used in TMR mixing', -1], // D-177: written only by a mixing batch
    ];

    /** D-177: types a person can choose on the stock form (mixing writes its own). */
    public static function manualTypes(): array
    {
        return array_diff_key(self::TYPES, array_flip(['mix_in', 'mix_out']));
    }

    public function item()
    {
        return $this->belongsTo(FeedItem::class, 'feed_item_id');
    }
}
