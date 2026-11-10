<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-177: a TMR recipe: feeds and their share in %. Its feed_item is the TMR itself (category TMR). */
class TmrFormula extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function lines()
    {
        return $this->hasMany(TmrFormulaLine::class);
    }

    public function item()
    {
        return $this->belongsTo(FeedItem::class, 'feed_item_id');
    }

    public function batches()
    {
        return $this->hasMany(TmrBatch::class);
    }
}
