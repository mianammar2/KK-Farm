<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RationPlanLine extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['kg_per_head' => 'float', 'share_pct' => 'float'];

    public function item()
    {
        return $this->belongsTo(FeedItem::class, 'feed_item_id');
    }
}
