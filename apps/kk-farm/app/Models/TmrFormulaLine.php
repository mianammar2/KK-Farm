<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TmrFormulaLine extends Model
{
    protected $guarded = [];

    protected $casts = ['pct' => 'float'];

    public function item()
    {
        return $this->belongsTo(FeedItem::class, 'feed_item_id');
    }
}
