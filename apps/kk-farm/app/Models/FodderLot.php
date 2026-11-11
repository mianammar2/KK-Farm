<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-202: a harvested fodder lot, held until tested and released into feed stock (or rejected). */
class FodderLot extends Model
{
    protected $guarded = [];

    protected $casts = ['harvested_on' => 'date', 'decided_at' => 'datetime', 'fresh_kg' => 'float', 'dm_pct' => 'float', 'aflatoxin_ppb' => 'float', 'cp_pct' => 'float',
        'mould' => 'boolean', 'smell_ok' => 'boolean', 'final_cut' => 'boolean'];

    public const FORMS = ['green_chop' => 'Green chop', 'silage' => 'Silage', 'hay' => 'Hay', 'haylage' => 'Haylage'];

    public const DM_METHODS = ['oven' => 'Oven drying', 'microwave' => 'Microwave drying', 'lab' => 'Laboratory', 'nir' => 'NIR analyser'];

    public function crop()
    {
        return $this->belongsTo(FodderCrop::class, 'crop_id');
    }

    public function item()
    {
        return $this->belongsTo(FeedItem::class, 'feed_item_id');
    }

    public function label(): string
    {
        return sprintf('FDR-%04d', $this->lot_no);
    }
}
