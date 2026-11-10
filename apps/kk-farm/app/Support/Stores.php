<?php

namespace App\Support;

use App\Models\FeedItem;
use App\Models\FeedStockMovement;
use App\Models\LedgerEntry;
use App\Models\Medicine;
use App\Models\MedicineMovement;
use App\Models\StoreItem;
use App\Models\StoreMove;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * D-178: the farm's three stores seen as one Inventory (feed, medicine, general items), and receiving goods into the
 * right store. A received purchase records its expense (a ledger entry, which posts its double-entry journal); bought
 * on credit it stays owed to the supplier until paid.
 */
final class Stores
{
    public const EXPENSE = ['feed' => 'Feed Purchase', 'medicine' => 'Medicine', 'store' => 'Farm Supplies'];

    /** @return Collection<int, float> general store quantity by item id */
    public static function generalQty(): Collection
    {
        return StoreMove::groupBy('store_item_id')->selectRaw('store_item_id, sum(qty) as q')->pluck('q', 'store_item_id')->map(fn ($q) => round((float) $q, 2));
    }

    public static function status(float $qty, float $reorder): string
    {
        return match (true) { $qty < 0 => 'negative', $qty == 0.0 => 'out', $reorder > 0 && $qty <= $reorder => 'low', default => 'ok' };
    }

    /** Every item in every store with what is on hand, its value and whether to reorder. */
    public static function overview(): array
    {
        $feedQty = Feed::stock();
        $medQty = MedicineStock::quantities();
        $genQty = self::generalQty();
        $rows = collect()
            ->merge(FeedItem::where('active', true)->orderBy('name')->get()->toBase()->map(fn (FeedItem $i) => self::row('feed', $i->id, $i->code, $i->name, FeedItem::CATEGORIES[$i->category] ?? $i->category, 'KG', (float) ($feedQty[$i->id] ?? 0), (float) $i->reorder_level_kg, $i->cost_per_kg_minor)))
            ->merge(Medicine::where('active', true)->orderBy('name')->get()->toBase()->map(fn (Medicine $m) => self::row('medicine', $m->id, $m->code, $m->name, Medicine::CATEGORIES[$m->category] ?? $m->category, $m->unit, (float) ($medQty[$m->id] ?? 0), (float) $m->reorder_level, $m->cost_per_unit_minor)))
            ->merge(StoreItem::where('active', true)->orderBy('name')->get()->toBase()->map(fn (StoreItem $s) => self::row('store', $s->id, $s->code, $s->name, StoreItem::CATEGORIES[$s->category] ?? $s->category, StoreItem::UNITS[$s->unit] ?? $s->unit, (float) ($genQty[$s->id] ?? 0), $s->reorder_level, $s->cost_per_unit_minor)));

        return $rows->values()->all();
    }

    private static function row(string $kind, int $id, ?string $code, string $name, string $category, string $unit, float $qty, float $reorder, int $costMinor): array
    {
        return ['kind' => $kind, 'id' => $id, 'code' => $code, 'name' => $name, 'category' => $category, 'unit' => $unit, 'qty' => round($qty, 2), 'reorder' => $reorder,
            'value' => round(max(0, $qty) * $costMinor / 100), 'cost' => $costMinor / 100, 'status' => self::status($qty, $reorder)];
    }

    /** The latest stock movements of all three stores, newest first. */
    public static function recentMoves(int $limit = 40): array
    {
        $feed = FeedStockMovement::with('item')->latest('id')->limit($limit)->get()->toBase()->map(fn ($m) => ['kind' => 'feed', 'at' => $m->created_at, 'on' => $m->moved_on, 'item' => $m->item?->name, 'type' => FeedStockMovement::TYPES[$m->type][0] ?? $m->type, 'qty' => (float) $m->qty_kg, 'unit' => 'KG', 'party' => $m->party, 'reference' => $m->reference, 'by' => $m->recorded_by]);
        $med = MedicineMovement::with('medicine')->latest('id')->limit($limit)->get()->toBase()->map(fn ($m) => ['kind' => 'medicine', 'at' => $m->created_at, 'on' => $m->moved_on, 'item' => $m->medicine?->name, 'type' => MedicineMovement::TYPES[$m->type][0] ?? $m->type, 'qty' => (float) $m->qty, 'unit' => $m->medicine?->unit, 'party' => $m->party, 'reference' => $m->reference, 'by' => $m->recorded_by]);
        $gen = StoreMove::with('item')->latest('id')->limit($limit)->get()->toBase()->map(fn ($m) => ['kind' => 'store', 'at' => $m->created_at, 'on' => $m->moved_on, 'item' => $m->item?->name, 'type' => StoreMove::TYPES[$m->type][0] ?? $m->type, 'qty' => (float) $m->qty, 'unit' => StoreItem::UNITS[$m->item?->unit] ?? $m->item?->unit, 'party' => $m->party ?? $m->issued_to, 'reference' => $m->reference, 'by' => $m->recorded_by]);

        return $feed->merge($med)->merge($gen)->sortByDesc('at')->take($limit)->map(fn ($m) => ['on' => $m['on']?->format('j M Y')] + $m)->map(fn ($m) => collect($m)->except('at')->all())->values()->all();
    }

    /**
     * Receive bought goods into their store and record the expense. $paid false keeps it owed to the supplier.
     * Feed and medicine are costed at this latest price from now on.
     */
    public static function receive(string $kind, int $itemId, float $qty, int $rateMinor, string $date, ?string $party, ?string $reference, string $by, array $extra = [], bool $paid = true): void
    {
        DB::transaction(function () use ($kind, $itemId, $qty, $rateMinor, $date, $party, $reference, $by, $extra, $paid) {
            [$name, $unit] = match ($kind) {
                'feed' => [FeedItem::findOrFail($itemId)->name, 'KG'],
                'medicine' => [($m = Medicine::findOrFail($itemId))->name, $m->unit],
                default => [($s = StoreItem::findOrFail($itemId))->name, StoreItem::UNITS[$s->unit] ?? $s->unit],
            };
            $ledger = $rateMinor > 0 ? LedgerEntry::create([
                'direction' => 'out', 'category' => self::EXPENSE[$kind], 'amount' => round($qty * $rateMinor / 100, 2), 'occurred_on' => $date,
                'counterparty' => $party, 'description' => "{$qty} {$unit} {$name}".($reference ? " · {$reference}" : ''), 'recorded_by' => $by,
                'payment_status' => $paid ? 'paid' : 'pending',
            ]) : null;
            $common = ['moved_on' => $date, 'type' => 'purchase', 'rate_minor' => $rateMinor, 'party' => $party, 'reference' => $reference, 'ledger_entry_id' => $ledger?->id, 'recorded_by' => $by, 'purchase_order_id' => $extra['purchase_order_id'] ?? null];
            match ($kind) {
                'feed' => [FeedStockMovement::create($common + ['feed_item_id' => $itemId, 'qty_kg' => $qty]), $rateMinor > 0 ? FeedItem::whereKey($itemId)->update(['cost_per_kg_minor' => $rateMinor]) : null],
                'medicine' => [MedicineMovement::create($common + ['medicine_id' => $itemId, 'qty' => $qty, 'batch' => $extra['batch'] ?? null, 'expiry_on' => $extra['expiry_on'] ?? null]), $rateMinor > 0 ? Medicine::whereKey($itemId)->update(['cost_per_unit_minor' => $rateMinor]) : null],
                default => [StoreMove::create($common + ['store_item_id' => $itemId, 'qty' => $qty]), $rateMinor > 0 ? StoreItem::whereKey($itemId)->update(['cost_per_unit_minor' => $rateMinor]) : null],
            };
            Tenant::flush();
        });
    }
}
