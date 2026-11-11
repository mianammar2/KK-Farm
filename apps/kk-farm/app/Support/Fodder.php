<?php

namespace App\Support;

use App\Models\FeedItem;
use App\Models\FeedStockMovement;
use App\Models\FodderCrop;
use App\Models\FodderInput;
use App\Models\FodderLot;
use App\Models\TmrFormulaLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * D-202: journey M Fodder-to-TMR (V3 flow 36) and App 22. Fodder → Quality → Inventory → Feeding → Finance:
 * no untested or unsafe fodder enters feed stock, and its dry matter is measured, not guessed. A harvest is refused
 * before a chemical's pre-harvest interval ends; a lot is released only with measured DM, a nitrate result, no mould,
 * a good smell and (for silage and haylage) aflatoxin within the farm limit, and by someone other than the harvester.
 * Its share of the crop's input costs becomes its cost per KG as fed and per KG DM; the input costs were expensed
 * when entered, so the stock receipt carries the cost without posting it again (BR-032).
 */
final class Fodder
{
    public const FERMENTED = ['silage', 'haylage'];

    public static function enabled(): bool
    {
        return (bool) Tenant::org()?->fodder_enabled;
    }

    /** Chemicals whose pre-harvest interval has not ended by this date. */
    public static function phiBlocks(FodderCrop $crop, string $date): array
    {
        return $crop->inputs()->where('kind', 'chemical')->whereNotNull('safe_from')->where('safe_from', '>', $date)->get()
            ->map(fn (FodderInput $i) => "{$i->product} (safe from {$i->safe_from->format('j M Y')})")->all();
    }

    /** What still stops a lot being released. */
    public static function releaseBlockers(FodderLot $lot): array
    {
        $limit = (int) (Tenant::org()?->aflatoxin_limit_ppb ?? 20);

        return array_values(array_filter([
            $lot->dm_pct === null || ! $lot->dm_method ? 'dry matter not measured' : null,
            $lot->nitrate === 'untested' ? 'nitrate not tested' : null,
            $lot->nitrate === 'high' ? 'high nitrate' : null,
            $lot->mould === null || $lot->smell_ok === null ? 'not inspected (mould, smell)' : null,
            $lot->mould ? 'mould found' : null,
            $lot->smell_ok === false ? 'bad smell' : null,
            in_array($lot->form, self::FERMENTED, true) && $lot->aflatoxin_ppb === null ? 'aflatoxin not tested' : null,
            $lot->aflatoxin_ppb !== null && $lot->aflatoxin_ppb > $limit ? "aflatoxin {$lot->aflatoxin_ppb} ppb above {$limit}" : null,
            ($p = self::phiBlocks($lot->crop, $lot->harvested_on->toDateString())) ? 'harvested inside a chemical interval: '.implode(', ', $p) : null,
        ]));
    }

    /** The lot's share of its crop's input costs: by fresh weight against the expected yield; the final cut takes the rest. */
    public static function allocate(FodderLot $lot): int
    {
        $crop = $lot->crop;
        $total = (int) $crop->inputs()->sum('cost_minor');
        $taken = (int) $crop->lots()->where('status', 'released')->where('id', '!=', $lot->id)->sum('cost_minor');
        $left = max(0, $total - $taken);
        if ($lot->final_cut) {
            return $left;
        }

        return min($left, (int) round($total * $lot->fresh_kg / max(1, $crop->expected_yield_kg)));
    }

    public static function release(FodderLot $lot, User $by, ?string $note = null): void
    {
        abort_unless($lot->status === 'quarantine', 422, 'This lot was already decided.');
        abort_unless(in_array($by->role, ['ceo', 'manager', 'vet'], true), 403, 'The owner, manager or vet releases fodder into feed stock.');
        if ($lot->harvested_by === $by->name && $by->role !== 'ceo') {
            throw ValidationException::withMessages(['lot' => 'Someone other than the harvester releases the lot.']);
        }
        if ($why = self::releaseBlockers($lot)) {
            throw ValidationException::withMessages(['lot' => 'Cannot release: '.implode('; ', $why).'.']);
        }
        DB::transaction(function () use ($lot, $by, $note) {
            $crop = $lot->crop;
            $cost = self::allocate($lot);
            $perKg = (int) round($cost / max(0.01, $lot->fresh_kg));
            $perKgDm = (int) round($cost / max(0.01, $lot->fresh_kg * $lot->dm_pct / 100));
            $code = strtoupper('FOD-'.substr($crop->crop, 0, 6).'-'.$lot->form);
            $item = FeedItem::firstOrCreate(['code' => $code], ['name' => (FodderCrop::CROPS[$crop->crop] ?? $crop->crop).' '.strtolower(FodderLot::FORMS[$lot->form]).' (own)', 'category' => 'roughage', 'active' => true]);
            // the item takes this lot's measured DM, CP and cost, so rations and TMR formulas use the real figures
            $item->update(['dm_pct' => $lot->dm_pct, 'cost_per_kg_minor' => $perKg] + ($lot->cp_pct !== null ? ['cp_pct' => $lot->cp_pct] : []));
            $move = FeedStockMovement::create(['feed_item_id' => $item->id, 'moved_on' => now()->toDateString(), 'type' => 'receipt', 'qty_kg' => $lot->fresh_kg, 'rate_minor' => $perKg,
                'party' => $crop->field?->name, 'reference' => "Fodder lot {$lot->label()}", 'notes' => "Own fodder, DM {$lot->dm_pct}% ({$lot->dm_method})", 'recorded_by' => $by->name]);
            $lot->update(['status' => 'released', 'decided_by' => $by->name, 'decided_at' => now(), 'decision_note' => $note, 'cost_minor' => $cost, 'cost_per_kg_minor' => $perKg,
                'cost_per_kg_dm_minor' => $perKgDm, 'feed_item_id' => $item->id, 'stock_movement_id' => $move->id]);
            if ($lot->final_cut) {
                $crop->update(['status' => 'closed']);
            }
            Tenant::flush();
        });
    }

    public static function reject(FodderLot $lot, User $by, string $note): void
    {
        abort_unless($lot->status === 'quarantine', 422, 'This lot was already decided.');
        abort_unless(in_array($by->role, ['ceo', 'manager', 'vet'], true), 403);
        $lot->update(['status' => 'rejected', 'decided_by' => $by->name, 'decided_at' => now(), 'decision_note' => $note]);
        if ($lot->final_cut) {
            $lot->crop->update(['status' => 'closed']);
        }
    }

    /** TMR formulas that use this feed item. */
    public static function formulasUsing(int $itemId): array
    {
        return TmrFormulaLine::with('formula')->where('feed_item_id', $itemId)->get()->map(fn ($l) => ['name' => $l->formula?->name, 'pct' => (float) $l->pct])->all();
    }

    /** @return array<string, mixed> yield per acre, farm cost per KG DM vs bought roughage, reject rate, lots released */
    public static function kpis(): array
    {
        $since = now()->subDays(365)->toDateString();
        $lots = FodderLot::with('crop')->where('harvested_on', '>=', $since)->get();
        $released = $lots->where('status', 'released');
        $decided = $lots->whereIn('status', ['released', 'rejected']);
        $acres = $lots->pluck('crop')->unique('id')->sum('area_acres');
        $dmKg = $released->sum(fn ($l) => $l->fresh_kg * $l->dm_pct / 100);
        $bought = FeedStockMovement::where('type', 'purchase')->whereHas('item', fn ($q) => $q->where('category', 'roughage')->where('code', 'not like', 'FOD-%'))
            ->where('moved_on', '>=', $since)->get();
        $boughtKg = $bought->sum('qty_kg');

        return [
            'yieldPerAcre' => $acres > 0 ? (int) round($lots->sum('fresh_kg') / $acres) : null,
            'dmPerAcre' => $acres > 0 && $released->count() ? (int) round($dmKg / $acres) : null,
            'costPerKgDm' => $dmKg > 0 ? (int) round($released->sum('cost_minor') / $dmKg) : null,
            'costPerKg' => $released->sum('fresh_kg') > 0 ? (int) round($released->sum('cost_minor') / $released->sum('fresh_kg')) : null,
            'boughtPerKg' => $boughtKg > 0 ? (int) round($bought->sum(fn ($m) => $m->qty_kg * $m->rate_minor) / $boughtKg) : null,
            'rejectRate' => $decided->count() ? (int) round($decided->where('status', 'rejected')->count() / $decided->count() * 100) : null,
            'released' => $released->count(), 'quarantine' => FodderLot::where('status', 'quarantine')->count(),
        ];
    }
}
