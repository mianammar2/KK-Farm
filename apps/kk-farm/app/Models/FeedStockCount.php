<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-192: a physical count of a feed: book vs counted; the difference is posted as an adjustment (shrinkage). */
class FeedStockCount extends Model
{
    protected $guarded = [];

    protected $casts = ['counted_on' => 'date', 'book_kg' => 'float', 'counted_kg' => 'float', 'diff_kg' => 'float', 'value_minor' => 'integer'];

    public function item()
    {
        return $this->belongsTo(FeedItem::class, 'feed_item_id');
    }
}
