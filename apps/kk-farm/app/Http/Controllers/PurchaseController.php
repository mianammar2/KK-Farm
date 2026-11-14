<?php

namespace App\Http\Controllers;

use App\Models\FeedItem;
use App\Models\Medicine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StoreItem;
use App\Models\Supplier;
use App\Support\Farm;
use App\Support\Stores;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** D-178: suppliers and purchase orders. Receiving an order puts each line into its store and records what is owed. */
class PurchaseController extends Controller
{
    public function index(Request $request): Response
    {
        $orders = PurchaseOrder::with(['supplier', 'lines'])->orderByRaw("case status when 'ordered' then 0 when 'draft' then 1 when 'received' then 2 else 3 end")->orderByDesc('ordered_on')->limit(100)->get();
        $names = [
            'feed' => FeedItem::pluck('name', 'id'), 'medicine' => Medicine::pluck('name', 'id'), 'store' => StoreItem::pluck('name', 'id'),
        ];
        $units = ['feed' => FeedItem::pluck('id')->mapWithKeys(fn ($id) => [$id => 'KG']), 'medicine' => Medicine::pluck('unit', 'id'), 'store' => StoreItem::pluck('unit', 'id')];

        return Inertia::render('Purchase', [
            'orders' => $orders->map(fn (PurchaseOrder $o) => [
                'id' => $o->id, 'no' => $o->po_no, 'supplier' => $o->supplier->name, 'supplierId' => $o->supplier_id, 'status' => $o->status, 'ordered' => $o->ordered_on->format('d/m/Y'),
                'expected' => $o->expected_on?->format('d/m/Y'), 'received' => $o->received_on?->format('d/m/Y'), 'receivedBy' => $o->received_by, 'notes' => $o->notes, 'total' => $o->totalMinor() / 100,
                'overdue' => $o->status === 'ordered' && $o->expected_on && $o->expected_on->isPast(),
                'lines' => $o->lines->map(fn (PurchaseOrderLine $l) => ['kind' => $l->kind, 'item' => $names[$l->kind][$l->item_id] ?? '?', 'unit' => $units[$l->kind][$l->item_id] ?? '', 'qty' => $l->qty, 'rate' => $l->rate_minor / 100, 'batch' => $l->batch, 'expiry' => $l->expiry_on?->format('d/m/Y')]),
            ]),
            'suppliers' => Supplier::orderByDesc('active')->orderBy('name')->get()->map(fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name, 'phone' => $s->phone, 'city' => $s->city, 'supplies' => $s->supplies, 'notes' => $s->notes, 'active' => $s->active,
                'orders' => $orders->where('supplier_id', $s->id)->count(), 'spent' => $orders->where('supplier_id', $s->id)->where('status', 'received')->sum(fn ($o) => $o->totalMinor()) / 100]),
            'catalog' => [
                'feed' => FeedItem::where('active', true)->where('category', '!=', 'tmr')->orderBy('name')->get(['id', 'name'])->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'unit' => 'KG']),
                'medicine' => Medicine::where('active', true)->orderBy('name')->get(['id', 'name', 'unit'])->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'unit' => $i->unit]),
                'store' => StoreItem::where('active', true)->orderBy('name')->get(['id', 'name', 'unit'])->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'unit' => StoreItem::UNITS[$i->unit] ?? $i->unit]),
            ],
            'kinds' => PurchaseOrderLine::KINDS, 'statuses' => PurchaseOrder::STATUSES,
            'can' => ['manage' => $request->user()->passes('manage_purchase')],
        ]);
    }

    private function supplierData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'], 'phone' => ['nullable', 'string', 'max:30'], 'city' => ['nullable', 'string', 'max:60'],
            'supplies' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:300'], 'active' => ['sometimes', 'boolean'],
        ]);
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_purchase'), 403);
        $s = Supplier::create($this->supplierData($request));
        Farm::log($request->user(), "Added supplier {$s->name}", '/purchase');

        return back()->with('success', "{$s->name} added.");
    }

    public function updateSupplier(Request $request, Supplier $supplier): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_purchase'), 403);
        $supplier->update($this->supplierData($request));

        return back()->with('success', "{$supplier->name} saved.");
    }

    public function storeOrder(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_purchase'), 403);
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'ordered_on' => ['required', 'date', 'before_or_equal:today'], 'expected_on' => ['nullable', 'date', 'after_or_equal:ordered_on'],
            'status' => ['required', Rule::in(['draft', 'ordered'])], 'notes' => ['nullable', 'string', 'max:300'],
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.kind' => ['required', Rule::in(array_keys(PurchaseOrderLine::KINDS))],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'lines.*.rate' => ['required', 'numeric', 'min:0', 'max:10000000'],
        ], [], ['lines.*.item_id' => 'item', 'lines.*.rate' => 'rate', 'lines.*.qty' => 'quantity', 'supplier_id' => 'supplier']);
        foreach ($data['lines'] as $i => $l) {
            $exists = match ($l['kind']) { 'feed' => FeedItem::whereKey($l['item_id'])->exists(), 'medicine' => Medicine::whereKey($l['item_id'])->exists(), default => StoreItem::whereKey($l['item_id'])->exists() };
            if (! $exists) {
                throw ValidationException::withMessages(["lines.$i.item_id" => 'Choose an item from the list.']);
            }
        }
        $order = DB::transaction(function () use ($data, $request) {
            DB::statement('LOCK TABLE purchase_orders IN SHARE ROW EXCLUSIVE MODE');
            $no = 'PO-'.now()->format('ym').'-'.str_pad((string) (PurchaseOrder::where('po_no', 'like', 'PO-'.now()->format('ym').'-%')->count() + 1), 3, '0', STR_PAD_LEFT);
            $order = PurchaseOrder::create(['po_no' => $no, 'supplier_id' => $data['supplier_id'], 'ordered_on' => $data['ordered_on'], 'expected_on' => $data['expected_on'] ?? null,
                'status' => $data['status'], 'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->name]);
            foreach ($data['lines'] as $l) {
                $order->lines()->create(['kind' => $l['kind'], 'item_id' => $l['item_id'], 'qty' => $l['qty'], 'rate_minor' => (int) round($l['rate'] * 100)]);
            }

            return $order;
        });
        Farm::log($request->user(), "Purchase order {$order->po_no} ({$order->status})", '/purchase');

        return back()->with('success', "{$order->po_no} saved as ".strtolower(PurchaseOrder::STATUSES[$order->status]).'.');
    }

    /** Draft → ordered, or cancel an order that has not been received. */
    public function status(Request $request, PurchaseOrder $order): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_purchase'), 403);
        $to = $request->validate(['status' => ['required', Rule::in(['ordered', 'cancelled'])]])['status'];
        if ($order->status === 'received' || $order->status === 'cancelled' || ($to === 'ordered' && $order->status !== 'draft')) {
            throw ValidationException::withMessages(['status' => 'This order cannot change any more.']);
        }
        $order->update(['status' => $to]);
        Farm::log($request->user(), "{$order->po_no}: ".PurchaseOrder::STATUSES[$to], '/purchase');

        return back()->with('success', "{$order->po_no} ".strtolower(PurchaseOrder::STATUSES[$to]).'.');
    }

    /** Goods arrived: every line goes into its store; the cost is recorded, paid now or owed to the supplier. */
    public function receive(Request $request, PurchaseOrder $order): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_purchase') || $request->user()->passes('manage_feed_store'), 403);
        $data = $request->validate([
            'received_on' => ['required', 'date', 'before_or_equal:today'], 'paid' => ['required', 'boolean'],
            'lines' => ['nullable', 'array'], 'lines.*.batch' => ['nullable', 'string', 'max:40'], 'lines.*.expiry_on' => ['nullable', 'date'],
        ]);
        if (! in_array($order->status, ['draft', 'ordered'], true)) {
            throw ValidationException::withMessages(['received_on' => 'This order was already received or cancelled.']);
        }
        $order->load('lines', 'supplier');
        foreach ($order->lines as $i => $l) {
            if ($l->kind === 'medicine' && empty($data['lines'][$i]['expiry_on'])) {
                throw ValidationException::withMessages(["lines.$i.expiry_on" => 'Enter the expiry date printed on the medicine pack.']);
            }
        }
        DB::transaction(function () use ($order, $data, $request) {
            foreach ($order->lines as $i => $l) {
                $extra = ['purchase_order_id' => $order->id, 'batch' => $data['lines'][$i]['batch'] ?? null, 'expiry_on' => $data['lines'][$i]['expiry_on'] ?? null];
                Stores::receive($l->kind, $l->item_id, $l->qty, $l->rate_minor, $data['received_on'], $order->supplier->name, $order->po_no, $request->user()->name, $extra, (bool) $data['paid']);
                if ($l->kind === 'medicine') {
                    $l->update(['batch' => $extra['batch'], 'expiry_on' => $extra['expiry_on']]);
                }
            }
            $order->update(['status' => 'received', 'received_on' => $data['received_on'], 'received_by' => $request->user()->name]);
        });
        Farm::log($request->user(), "Received {$order->po_no} from {$order->supplier->name}", '/purchase');

        return back()->with('success', "{$order->po_no} received: goods are in store".($data['paid'] ? ' and the payment recorded.' : '; the amount is owed to '.$order->supplier->name.' until paid in Expenses.'));
    }
}
