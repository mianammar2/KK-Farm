<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-209: a received feed lot; a feed that needs a safety test waits in quarantine until a second person releases it. */
class FeedLot extends Model
{
    protected $guarded = [];

    protected $casts = ['received_on' => 'date', 'qty_kg' => 'float', 'moisture_pct' => 'float', 'aflatoxin_ppb' => 'float', 'tested_at' => 'datetime', 'decided_at' => 'datetime'];

    public const STATUS = ['quarantined' => 'Quarantined: waiting for test', 'released' => 'Released', 'rejected' => 'Rejected'];

    public function item()
    {
        return $this->belongsTo(FeedItem::class, 'feed_item_id');
    }
}
