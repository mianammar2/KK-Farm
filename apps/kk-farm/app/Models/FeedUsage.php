<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * D-054: feed offered to one pen at one meal, one row per feed (workbook "Feed Usage"). Append-only: a correction,
 * including leftover entered later, is a new row whose supersedes_id points at the row it replaces.
 */
class FeedUsage extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['fed_on' => 'date', 'fed_at' => 'datetime', 'planned_kg' => 'float', 'planned_min_kg' => 'float', 'planned_max_kg' => 'float', 'offered_kg' => 'float', 'refused_kg' => 'float', 'wasted_kg' => 'float', 'unit_cost_minor' => 'integer', 'headcount' => 'integer'];

    /** Rows that no later correction has replaced. */
    public function scopeCurrent(Builder $q): Builder
    {
        return $q->whereNotExists(fn ($s) => $s->from('feed_usages as c')->whereColumn('c.supersedes_id', 'feed_usages.id'));
    }

    public function consumedKg(): ?float
    {
        return max(0, $this->offered_kg - ($this->refused_kg ?? 0) - ($this->wasted_kg ?? 0)); // D-177: wasted feed was not eaten
    }

    public function item()
    {
        return $this->belongsTo(FeedItem::class, 'feed_item_id');
    }

    public function pen()
    {
        return $this->belongsTo(Pen::class);
    }
}
