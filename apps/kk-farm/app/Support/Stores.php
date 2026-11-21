<?php

namespace App\Support;

use App\Models\FeedItem;
use App\Models\FeedStockMovement;
use App\Models\FeedUsage;
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
    /** D-241 (RBAC §4 Inventory): which stores (feed, medicine, store) a person sees. Finance sees all, as valuation. */
    public static function visibleTo(\App\Models\User $u): array
    {
        return match ($u->role) {
            'vet' => ['medicine'],
            'feeding' => ['feed'],
            'caretaker', 'cleaning' => ['store'],
            default => ['feed', 'medicine', 'store'],
        };
    }

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

    /** D-331: the store labels, the apps that use each store, and the right that manages it. */
    public const KINDS = ['feed' => 'Feed', 'medicine' => 'Medicine', 'store' => 'General'];

    public const GATES = ['feed' => 'manage_feed_store', 'medicine' => 'manage_medicine_store', 'store' => 'manage_general_store'];

    /**
     * D-331: every item of the given stores, in use or not, with all it needs to be shown and edited. One record per
     * item: Feeding, Veterinary, Purchase and Finance read the same rows, so the figures cannot disagree.
     */
    public static function items(array $stores): array
    {
        $rows = collect();
        if (in_array('feed', $stores, true)) {
            $q = Feed::stock();
            $rows = $rows->merge(FeedItem::orderByDesc('active')->orderBy('name')->get()->toBase()->map(fn (FeedItem $i) => self::row('feed', $i->id, $i->code, $i->name, FeedItem::CATEGORIES[$i->category] ?? $i->category, 'KG', (float) ($q[$i->id] ?? 0), (float) $i->reorder_level_kg, $i->cost_per_kg_minor)
                + ['categoryKey' => $i->category, 'unitKey' => 'kg', 'active' => (bool) $i->active, 'supplier' => $i->supplier, 'storage' => null, 'notes' => $i->notes, 'cp' => $i->cp_pct !== null ? (float) $i->cp_pct : null, 'dm' => $i->dm_pct !== null ? (float) $i->dm_pct : null, 'spec' => null, 'useCase' => null, 'source' => null, 'packUnit' => null, 'packSize' => null, 'check' => null, 'checkNote' => null, 'diseaseIds' => []]));
        }
        if (in_array('medicine', $stores, true)) {
            $q = MedicineStock::quantities();
            $rows = $rows->merge(Medicine::with('diseases')->orderByDesc('active')->orderBy('name')->get()->toBase()->map(fn (Medicine $m) => self::row('medicine', $m->id, $m->code, $m->name, Medicine::CATEGORIES[$m->category] ?? $m->category, $m->unit, (float) ($q[$m->id] ?? 0), (float) $m->reorder_level, $m->cost_per_unit_minor)
                + ['categoryKey' => $m->category, 'unitKey' => $m->unit, 'active' => (bool) $m->active, 'supplier' => $m->supplier, 'storage' => $m->storage, 'notes' => $m->notes, 'cp' => null, 'dm' => null] + self::medicineDetails($m)));
        }
        if (in_array('store', $stores, true)) {
            $q = self::generalQty();
            $rows = $rows->merge(StoreItem::orderByDesc('active')->orderBy('name')->get()->toBase()->map(fn (StoreItem $s) => self::row('store', $s->id, $s->code, $s->name, StoreItem::CATEGORIES[$s->category] ?? $s->category, StoreItem::UNITS[$s->unit] ?? $s->unit, (float) ($q[$s->id] ?? 0), (float) $s->reorder_level, $s->cost_per_unit_minor)
                + ['categoryKey' => $s->category, 'unitKey' => $s->unit, 'active' => (bool) $s->active, 'supplier' => null, 'storage' => null, 'notes' => $s->notes, 'cp' => null, 'dm' => null, 'spec' => null, 'useCase' => null, 'source' => null, 'packUnit' => null, 'packSize' => null, 'check' => null, 'checkNote' => null, 'diseaseIds' => []]));
        }

        return $rows->values()->all();
    }

    /** D-332: a medicine's label details, purchase pack and vet check, as the pages show and edit them. */
    public static function medicineDetails(Medicine $m): array
    {
        return ['spec' => $m->spec, 'useCase' => $m->use_case, 'source' => $m->source, 'packUnit' => $m->pack_unit, 'packSize' => $m->pack_size, 'check' => $m->check_status, 'checkNote' => $m->check_note,
            'diseaseIds' => $m->diseases->pluck('id')->all(), // D-333
            'dose' => ['qty' => $m->dose_qty !== null ? (float) $m->dose_qty : null, 'route' => $m->dose_route, 'frequency' => $m->dose_frequency, 'days' => $m->dose_days,
                'meatDays' => $m->meat_withdrawal_days, 'milkDays' => $m->milk_withdrawal_days]]; // D-369: the standard dose
    }

    /** D-331: medicine batches that still hold stock and have an expiry date, earliest first, with days left. */
    public static function expiry(): array
    {
        $names = Medicine::pluck('name', 'id');
        $units = Medicine::pluck('unit', 'id');
        $today = now()->startOfDay();

        return MedicineStock::batches()->flatten(1)->filter(fn ($b) => $b['expiry'] !== null)->sortBy(fn ($b) => $b['expiry']->timestamp)->values()
            ->map(fn ($b) => ['id' => $b['medicine_id'], 'item' => $names[$b['medicine_id']] ?? '', 'batch' => $b['batch'], 'qty' => $b['qty'], 'unit' => $units[$b['medicine_id']] ?? '',
                'expiry' => $b['expiry']->format('d/m/Y'), 'days' => (int) $today->diffInDays($b['expiry'], false),
                'state' => $b['expiry']->lessThan($today) ? 'expired' : ($b['expiry']->lessThanOrEqualTo($today->copy()->addDays(MedicineStock::EXPIRING_DAYS)) ? 'expiring' : 'ok')])->all();
    }

    /**
     * D-331: every stock movement of the stores a person sees, newest first, including feed given to goats (one line per
     * feed per day), so the ledger adds up to what is on hand. Optional: one item, a date range.
     */
    public static function ledger(array $stores, ?string $kind = null, ?int $itemId = null, ?string $from = null, ?string $to = null, int $limit = 1000): array
    {
        $range = fn ($q, string $col) => $q->when($from, fn ($x) => $x->where($col, '>=', $from))->when($to, fn ($x) => $x->where($col, '<=', $to));
        $want = fn (string $k) => in_array($k, $stores, true) && (! $kind || $kind === $k);
        $rows = collect();
        if ($want('feed')) {
            $rows = $rows->merge($range(FeedStockMovement::with('item'), 'moved_on')->when($itemId, fn ($q) => $q->where('feed_item_id', $itemId))->latest('moved_on')->latest('id')->limit($limit)->get()->toBase()
                ->map(fn ($m) => ['kind' => 'feed', 'itemId' => $m->feed_item_id, 'd' => $m->moved_on?->toDateString(), 'at' => $m->created_at?->timestamp, 'item' => $m->item?->name, 'typeKey' => $m->type, 'type' => FeedStockMovement::TYPES[$m->type][0] ?? $m->type, 'qty' => (float) $m->qty_kg, 'unit' => 'KG', 'rate' => $m->rate_minor !== null ? $m->rate_minor / 100 : null, 'batch' => null, 'party' => $m->party, 'reference' => $m->reference, 'notes' => $m->notes, 'by' => $m->recorded_by]));
            $used = $range(FeedUsage::current(), 'fed_on')->when($itemId, fn ($q) => $q->where('feed_item_id', $itemId))->groupBy('fed_on', 'feed_item_id')
                ->selectRaw('fed_on, feed_item_id, sum(offered_kg) as kg, count(*) as n')->orderByDesc('fed_on')->limit($limit)->get();
            $feedNames = FeedItem::pluck('name', 'id');
            $rows = $rows->merge($used->toBase()->map(fn ($u) => ['kind' => 'feed', 'itemId' => $u->feed_item_id, 'd' => \Illuminate\Support\Carbon::parse($u->fed_on)->toDateString(), 'at' => \Illuminate\Support\Carbon::parse($u->fed_on)->endOfDay()->timestamp, 'item' => $feedNames[$u->feed_item_id] ?? '',
                'typeKey' => 'fed', 'type' => 'Fed to goats', 'qty' => -round((float) $u->kg, 2), 'unit' => 'KG', 'rate' => null, 'batch' => null, 'party' => null, 'reference' => "{$u->n} feedings", 'notes' => null, 'by' => 'Feeding']));
        }
        if ($want('medicine')) {
            $rows = $rows->merge($range(MedicineMovement::with('medicine'), 'moved_on')->when($itemId, fn ($q) => $q->where('medicine_id', $itemId))->latest('moved_on')->latest('id')->limit($limit)->get()->toBase()
                ->map(fn ($m) => ['kind' => 'medicine', 'itemId' => $m->medicine_id, 'd' => $m->moved_on?->toDateString(), 'at' => $m->created_at?->timestamp, 'item' => $m->medicine?->name, 'typeKey' => $m->type, 'type' => MedicineMovement::TYPES[$m->type][0] ?? $m->type, 'qty' => (float) $m->qty, 'unit' => $m->medicine?->unit, 'rate' => $m->rate_minor !== null ? $m->rate_minor / 100 : null, 'batch' => $m->batch, 'party' => $m->party, 'reference' => $m->reference, 'notes' => $m->notes, 'by' => $m->recorded_by]));
        }
        if ($want('store')) {
            $rows = $rows->merge($range(StoreMove::with('item'), 'moved_on')->when($itemId, fn ($q) => $q->where('store_item_id', $itemId))->latest('moved_on')->latest('id')->limit($limit)->get()->toBase()
                ->map(fn ($m) => ['kind' => 'store', 'itemId' => $m->store_item_id, 'd' => $m->moved_on?->toDateString(), 'at' => $m->created_at?->timestamp, 'item' => $m->item?->name, 'typeKey' => $m->type, 'type' => StoreMove::TYPES[$m->type][0] ?? $m->type, 'qty' => (float) $m->qty, 'unit' => StoreItem::UNITS[$m->item?->unit] ?? $m->item?->unit, 'rate' => $m->rate_minor !== null ? $m->rate_minor / 100 : null, 'batch' => null, 'party' => $m->party ?? $m->issued_to, 'reference' => $m->reference, 'notes' => $m->notes, 'by' => $m->recorded_by]));
        }

        return $rows->sortBy([['d', 'desc'], ['at', 'desc']])->take($limit)
            ->map(fn ($m) => ['on' => $m['d'] ? \Illuminate\Support\Carbon::parse($m['d'])->format('d/m/Y') : null] + collect($m)->except('at')->all())->values()->all();
    }

    /**
     * D-331: a physical count of one store. Each item's difference to the book is posted once as an "Adjustment after
     * count" (shrinkage when less was found); a feed count also keeps its count record (D-192). Returns how many items.
     *
     * @param  array<int, array{id: int, counted: float}>  $counts
     */
    public static function count(string $kind, array $counts, string $on, ?string $notes, string $by): int
    {
        return DB::transaction(function () use ($kind, $counts, $on, $notes, $by) {
            $table = ['feed' => 'feed_stock_movements', 'medicine' => 'medicine_movements', 'store' => 'store_moves'][$kind];
            DB::statement("LOCK TABLE {$table} IN SHARE ROW EXCLUSIVE MODE");
            $book = match ($kind) { 'feed' => Feed::stock(true), 'medicine' => MedicineStock::quantities(), default => self::generalQty() };
            $common = ['moved_on' => $on, 'type' => 'adjustment', 'reference' => 'Stock count', 'recorded_by' => $by];
            $n = 0;
            foreach ($counts as $c) {
                $was = (float) ($book[$c['id']] ?? 0);
                $diff = round((float) $c['counted'] - $was, 2);
                $note = $diff < 0 ? 'Shrinkage found at count' : 'Surplus found at count';
                $note = $notes ? "{$note} · {$notes}" : $note;
                if ($kind === 'feed') {
                    $item = FeedItem::findOrFail($c['id']);
                    $mv = abs($diff) >= 0.01 ? FeedStockMovement::create($common + ['feed_item_id' => $item->id, 'qty_kg' => $diff, 'rate_minor' => $item->cost_per_kg_minor, 'notes' => $note]) : null;
                    \App\Models\FeedStockCount::create(['feed_item_id' => $item->id, 'counted_on' => $on, 'book_kg' => $was, 'counted_kg' => $c['counted'], 'diff_kg' => $diff,
                        'value_minor' => (int) round($diff * $item->cost_per_kg_minor), 'movement_id' => $mv?->id, 'notes' => $notes, 'counted_by' => $by]);
                } elseif (abs($diff) >= 0.01 && $kind === 'medicine') {
                    $m = Medicine::findOrFail($c['id']);
                    MedicineMovement::create($common + ['medicine_id' => $m->id, 'qty' => $diff, 'rate_minor' => $m->cost_per_unit_minor, 'notes' => $note]);
                } elseif (abs($diff) >= 0.01) {
                    $s = StoreItem::findOrFail($c['id']);
                    StoreMove::create($common + ['store_item_id' => $s->id, 'qty' => $diff, 'rate_minor' => $s->cost_per_unit_minor, 'notes' => $note]);
                }
                $n++;
            }
            Tenant::flush();

            return $n;
        });
    }

    /** The latest stock movements of all three stores, newest first. */
    public static function recentMoves(int $limit = 40): array
    {
        $feed = FeedStockMovement::with('item')->latest('id')->limit($limit)->get()->toBase()->map(fn ($m) => ['kind' => 'feed', 'at' => $m->created_at, 'on' => $m->moved_on, 'item' => $m->item?->name, 'type' => FeedStockMovement::TYPES[$m->type][0] ?? $m->type, 'qty' => (float) $m->qty_kg, 'unit' => 'KG', 'party' => $m->party, 'reference' => $m->reference, 'by' => $m->recorded_by]);
        $med = MedicineMovement::with('medicine')->latest('id')->limit($limit)->get()->toBase()->map(fn ($m) => ['kind' => 'medicine', 'at' => $m->created_at, 'on' => $m->moved_on, 'item' => $m->medicine?->name, 'type' => MedicineMovement::TYPES[$m->type][0] ?? $m->type, 'qty' => (float) $m->qty, 'unit' => $m->medicine?->unit, 'party' => $m->party, 'reference' => $m->reference, 'by' => $m->recorded_by]);
        $gen = StoreMove::with('item')->latest('id')->limit($limit)->get()->toBase()->map(fn ($m) => ['kind' => 'store', 'at' => $m->created_at, 'on' => $m->moved_on, 'item' => $m->item?->name, 'type' => StoreMove::TYPES[$m->type][0] ?? $m->type, 'qty' => (float) $m->qty, 'unit' => StoreItem::UNITS[$m->item?->unit] ?? $m->item?->unit, 'party' => $m->party ?? $m->issued_to, 'reference' => $m->reference, 'by' => $m->recorded_by]);

        return $feed->merge($med)->merge($gen)->sortByDesc('at')->take($limit)->map(fn ($m) => ['on' => $m['on']?->format('d/m/Y')] + $m)->map(fn ($m) => collect($m)->except('at')->all())->values()->all();
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
