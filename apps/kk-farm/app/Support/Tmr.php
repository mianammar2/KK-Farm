<?php

namespace App\Support;

use App\Models\FeedItem;
use App\Models\FeedStockMovement;
use App\Models\FeedUsage;
use App\Models\TmrBatch;
use App\Models\TmrFormula;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * D-177: TMR formulas, mixing batches and the feed offered / refused / wasted report.
 * A formula's CP, DM and cost per KG come from its ingredients (share × value); missing values are reported as such.
 */
final class Tmr
{
    /** CP %, DM % and cost per KG (minor units) of a recipe [[item, pct], …]; CP/DM null when an ingredient has none. */
    public static function analyse(iterable $lines): array
    {
        $cp = $dm = 0.0;
        $cost = 0.0;
        $cpKnown = $dmKnown = true;
        foreach ($lines as [$item, $pct]) {
            $share = $pct / 100;
            $item->cp_pct === null ? $cpKnown = false : $cp += $share * $item->cp_pct;
            $item->dm_pct === null ? $dmKnown = false : $dm += $share * $item->dm_pct;
            $cost += $share * $item->cost_per_kg_minor;
        }

        return ['cp' => $cpKnown ? round($cp, 1) : null, 'dm' => $dmKnown ? round($dm, 1) : null, 'costMinor' => (int) round($cost)];
    }

    /** Validated recipe lines: distinct feeds that are not TMRs, shares adding up to 100 %. */
    public static function checkLines(array $lines, ?int $selfItemId = null): array
    {
        $ids = array_column($lines, 'feed_item_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['lines' => 'Each feed can be in the recipe only once.']);
        }
        $items = FeedItem::whereIn('id', $ids)->get()->keyBy('id');
        foreach ($lines as $i => $l) {
            $item = $items[$l['feed_item_id']] ?? null;
            if (! $item || $item->category === 'tmr' || $item->id === $selfItemId) {
                throw ValidationException::withMessages(["lines.$i.feed_item_id" => 'A TMR recipe is made of feeds, not of another TMR.']);
            }
        }
        $sum = round(array_sum(array_column($lines, 'pct')), 2);
        if (abs($sum - 100) > 0.05) {
            throw ValidationException::withMessages(['lines' => "The shares add up to {$sum} %. They must add up to 100 %."]);
        }

        return array_map(fn ($l) => [$items[$l['feed_item_id']], (float) $l['pct']], $lines);
    }

    /**
     * Mix a batch: each ingredient leaves stock (share × total), the TMR enters stock at the ingredients' cost.
     * D-192: when the mixed TMR is weighed ($outputKg), that is what enters stock and the ingredients' cost is spread over
     * it, so mixing shrinkage raises the TMR cost per KG instead of disappearing.
     */
    public static function mix(TmrFormula $formula, float $totalKg, string $mixedOn, User $by, ?string $notes = null, ?float $outputKg = null): TmrBatch
    {
        if ($outputKg !== null && ($outputKg <= 0 || $outputKg > $totalKg * 1.05)) {
            throw ValidationException::withMessages(['output_kg' => 'The weighed TMR cannot be more than the ingredients put in.']);
        }

        return DB::transaction(function () use ($formula, $totalKg, $mixedOn, $by, $notes, $outputKg) {
            DB::statement('LOCK TABLE feed_stock_movements IN SHARE ROW EXCLUSIVE MODE');
            $formula->load('lines.item', 'item');
            if (! $formula->active || $formula->lines->isEmpty()) {
                throw ValidationException::withMessages(['total_kg' => 'This formula is not in use.']);
            }
            Quality::assertFeedNotHeld($formula->lines->pluck('feed_item_id')->all(), 'total_kg'); // D-189 / BR-016
            $stock = Feed::usable(true); // D-209: quarantined feed lots cannot be mixed (BR-016)
            $need = $formula->lines->map(fn ($l) => ['item' => $l->item, 'kg' => round($totalKg * $l->pct / 100, 2)]);
            $short = $need->filter(fn ($n) => ($stock[$n['item']->id] ?? 0) < $n['kg']);
            if ($short->isNotEmpty()) {
                throw ValidationException::withMessages(['total_kg' => 'Not enough in store: '.$short->map(fn ($n) => "{$n['item']->name} needs {$n['kg']} KG, ".max(0, $stock[$n['item']->id] ?? 0).' KG usable'.(($lots = Feed::quarantinedLots([$n['item']->id])) ? " (lot {$lots} waiting for its safety test)" : ''))->implode('; ').'.']);
            }
            $cost = (int) round($need->sum(fn ($n) => $n['kg'] * $n['item']->cost_per_kg_minor));
            $no = 'TMR-'.now()->format('ymd').'-'.str_pad((string) (TmrBatch::whereDate('created_at', today())->count() + 1), 2, '0', STR_PAD_LEFT);
            $batch = TmrBatch::create(['tmr_formula_id' => $formula->id, 'batch_no' => $no, 'mixed_on' => $mixedOn, 'total_kg' => $totalKg, 'output_kg' => $outputKg, 'cost_minor' => $cost, 'mixed_by' => $by->name, 'notes' => $notes]);
            foreach ($need as $n) {
                FeedStockMovement::create(['feed_item_id' => $n['item']->id, 'moved_on' => $mixedOn, 'type' => 'mix_out', 'qty_kg' => -$n['kg'], 'rate_minor' => $n['item']->cost_per_kg_minor, 'reference' => $no, 'tmr_batch_id' => $batch->id, 'recorded_by' => $by->name]);
            }
            $made = $outputKg ?? $totalKg;
            $rate = $made > 0 ? (int) round($cost / $made) : 0;
            FeedStockMovement::create(['feed_item_id' => $formula->feed_item_id, 'moved_on' => $mixedOn, 'type' => 'mix_in', 'qty_kg' => $made, 'rate_minor' => $rate, 'reference' => $no, 'tmr_batch_id' => $batch->id, 'recorded_by' => $by->name]);
            $formula->item->update(['cost_per_kg_minor' => $rate]); // the TMR is costed at its latest batch
            Tenant::flush();

            return $batch;
        });
    }

    /** Formulas with their recipe, analysis and recent batches, for the Feeding page. */
    public static function board(): array
    {
        $stock = Feed::stock();
        $formulas = TmrFormula::with(['lines.item', 'item'])->orderByDesc('active')->orderBy('name')->get();

        return [
            'formulas' => $formulas->map(fn (TmrFormula $f) => [
                'id' => $f->id, 'name' => $f->name, 'code' => $f->item->code, 'active' => $f->active, 'notes' => $f->notes, 'stock' => (float) ($stock[$f->feed_item_id] ?? 0),
                'lines' => $f->lines->map(fn ($l) => ['itemId' => $l->feed_item_id, 'name' => $l->item->name, 'pct' => $l->pct, 'cp' => $l->item->cp_pct, 'stock' => (float) ($stock[$l->feed_item_id] ?? 0)])->values(),
            ] + self::analyse($f->lines->map(fn ($l) => [$l->item, $l->pct]))),
            'batches' => TmrBatch::with('formula')->orderByDesc('mixed_on')->orderByDesc('id')->limit(30)->get()->map(fn (TmrBatch $b) => [
                'id' => $b->id, 'no' => $b->batch_no, 'formula' => $b->formula->name, 'on' => $b->mixed_on->format('j M Y'), 'kg' => $b->total_kg, 'cost' => $b->cost_minor / 100, 'by' => $b->mixed_by,
            ]),
        ];
    }

    /** Feed offered, refused and wasted per pen and feed over the last $days days. */
    public static function wasteReport(int $days = 7): array
    {
        $rows = FeedUsage::current()->with(['item', 'pen.shed'])->where('fed_on', '>=', now()->subDays($days - 1)->toDateString())->get();

        $lines = $rows->groupBy(fn ($u) => $u->pen_id.'|'.$u->feed_item_id)->map(function ($g) {
            $u = $g->first();
            $offered = round($g->sum('offered_kg'), 1);
            $refused = round($g->sum(fn ($x) => $x->refused_kg ?? 0), 1);
            $wasted = round($g->sum(fn ($x) => $x->wasted_kg ?? 0), 1);

            return ['pen' => $u->pen?->fullName() ?? '—', 'feed' => $u->item?->name ?? '—', 'offered' => $offered, 'refused' => $refused, 'wasted' => $wasted,
                'eaten' => round(max(0, $offered - $refused - $wasted), 1), 'wastePct' => $offered > 0 ? round(($refused + $wasted) / $offered * 100, 1) : 0, 'meals' => $g->count()];
        })->sortByDesc('wastePct')->values();

        $sum = fn ($k) => round($lines->sum($k), 1);

        return ['days' => $days, 'rows' => $lines->all(), 'total' => ['offered' => $sum('offered'), 'refused' => $sum('refused'), 'wasted' => $sum('wasted'), 'eaten' => $sum('eaten'),
            'wastePct' => $sum('offered') > 0 ? round(($sum('refused') + $sum('wasted')) / $sum('offered') * 100, 1) : 0]];
    }
}
