<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\FeedItem;
use App\Models\FeedStockCount;
use App\Models\FeedStockMovement;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\TmrBatch;
use App\Support\Farm;
use App\Support\Feed;
use App\Support\FeedCost;
use App\Support\FeedRequirement;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-192: V3 cross-app journey C Feed-to-True-Cost (flow 26):
 * feeding group and CP target approved → daily pen-level forecast → stock and ingredient quality available? No: purchase
 * and goods receipt (a draft purchase order from the shortfall). Yes: TMR mix with lot traceability (batch number,
 * weighed output = mixing shrinkage) → deliver 3 meals, record offered KG → record refusals → issue stock and value
 * consumption → distribute pen costs to animals → check ADG, BCS and health exceptions → poor response: review intake,
 * ration and illness (adjust plan) → acceptable: actual cost per KG gained and margin.
 * Key control: allocation assumptions are shown (FeedCost::ASSUMPTIONS); counts capture shrinkage; feed is issued once
 * (each meal row is the issue; corrections supersede, never add).
 */
class FeedCostController extends Controller
{
    private function canCount(Request $request): bool
    {
        return $request->user()->passes('manage_feed_store');
    }

    public function index(Request $request): Response
    {
        $to = $request->date('to') ?? Feed::now()->startOfDay();
        $from = $request->date('from') ?? $to->copy()->subDays(29);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 92) {
            $from = $to->copy()->subDays(92); // one quarter at most
        }
        $data = FeedCost::build(Carbon::parse($from), Carbon::parse($to));
        $req = FeedRequirement::build(\App\Models\Animal::with(['pen.shed'])->active()->get());
        $stock = Feed::stock();

        return Inertia::render('FeedCost', $data + [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'assumptions' => FeedCost::ASSUMPTIONS,
            'supply' => $req['supply'],
            'items' => FeedItem::where('active', true)->orderBy('name')->get()->map(fn (FeedItem $i) => ['id' => $i->id, 'name' => $i->name, 'book' => round((float) ($stock[$i->id] ?? 0), 1), 'cost' => $i->cost_per_kg_minor / 100, 'supplier' => $i->supplier])->values(),
            'counts' => FeedStockCount::with('item')->orderByDesc('counted_on')->orderByDesc('id')->limit(40)->get()->map(fn (FeedStockCount $c) => [
                'id' => $c->id, 'on' => $c->counted_on->format('d/m/Y'), 'item' => $c->item?->name, 'book' => $c->book_kg, 'counted' => $c->counted_kg, 'diff' => $c->diff_kg, 'value' => $c->value_minor / 100, 'by' => $c->counted_by, 'notes' => $c->notes,
            ]),
            'tmr' => TmrBatch::with('formula')->orderByDesc('mixed_on')->orderByDesc('id')->limit(20)->get()->map(fn (TmrBatch $b) => [
                'no' => $b->batch_no, 'on' => $b->mixed_on->format('d/m'), 'formula' => $b->formula?->name, 'input' => (float) $b->total_kg, 'output' => $b->output_kg !== null ? (float) $b->output_kg : null,
                'shrink' => $b->output_kg !== null && $b->total_kg > 0 ? round((1 - $b->output_kg / $b->total_kg) * 100, 1) : null, 'cost' => $b->cost_minor / 100,
            ]),
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(['id', 'name']),
            'fcrAlert' => (float) (Tenant::org()?->fcr_alert ?? 10),
            'canCount' => $this->canCount($request), 'canPurchase' => $request->user()->passes('manage_purchase'),
            'canReview' => in_array($request->user()->role, ['ceo', 'manager', 'vet', 'feeding'], true),
        ]);
    }

    /** Physical count: the difference to the book is posted once as an adjustment (shrinkage when negative). */
    public function count(Request $request): RedirectResponse
    {
        abort_unless($this->canCount($request), 403);
        $data = $request->validate(['counts' => ['required', 'array', 'min:1', 'max:100'], 'counts.*.feed_item_id' => ['required', 'distinct', 'exists:feed_items,id'],
            'counts.*.counted_kg' => ['required', 'numeric', 'min:0', 'max:10000000'], 'counted_on' => ['required', 'date', 'before_or_equal:today'], 'notes' => ['nullable', 'string', 'max:200']]);
        $n = DB::transaction(function () use ($data, $request) {
            DB::statement('LOCK TABLE feed_stock_movements IN SHARE ROW EXCLUSIVE MODE');
            $book = Feed::stock(true);
            $n = 0;
            foreach ($data['counts'] as $c) {
                $item = FeedItem::findOrFail($c['feed_item_id']);
                $was = (float) ($book[$item->id] ?? 0);
                $diff = round((float) $c['counted_kg'] - $was, 2);
                $mv = abs($diff) >= 0.01 ? FeedStockMovement::create(['feed_item_id' => $item->id, 'moved_on' => $data['counted_on'], 'type' => 'adjustment', 'qty_kg' => $diff,
                    'rate_minor' => $item->cost_per_kg_minor, 'reference' => 'Stock count', 'notes' => $diff < 0 ? 'Shrinkage found at count' : 'Surplus found at count', 'recorded_by' => $request->user()->name]) : null;
                FeedStockCount::create(['feed_item_id' => $item->id, 'counted_on' => $data['counted_on'], 'book_kg' => $was, 'counted_kg' => $c['counted_kg'], 'diff_kg' => $diff,
                    'value_minor' => (int) round($diff * $item->cost_per_kg_minor), 'movement_id' => $mv?->id, 'notes' => $data['notes'] ?? null, 'counted_by' => $request->user()->name]);
                $n++;
            }
            Tenant::flush();

            return $n;
        });
        Farm::log($request->user(), "Counted {$n} feeds in the store", '/feed-cost?tab=stock');

        return back()->with('success', "Stock count saved for {$n} feeds. Differences are posted as adjustments.");
    }

    /** Stock not available → purchase: a draft purchase order for this week's shortfall. */
    public function purchase(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_purchase'), 403);
        $data = $request->validate(['supplier_id' => ['required', 'exists:suppliers,id'], 'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.feed_item_id' => ['required', 'distinct', 'exists:feed_items,id'], 'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:10000000']], [], ['supplier_id' => 'supplier']);
        $order = DB::transaction(function () use ($data, $request) {
            DB::statement('LOCK TABLE purchase_orders IN SHARE ROW EXCLUSIVE MODE');
            $no = 'PO-'.now()->format('ym').'-'.str_pad((string) (PurchaseOrder::where('po_no', 'like', 'PO-'.now()->format('ym').'-%')->count() + 1), 3, '0', STR_PAD_LEFT);
            $order = PurchaseOrder::create(['po_no' => $no, 'supplier_id' => $data['supplier_id'], 'ordered_on' => now()->toDateString(), 'status' => 'draft',
                'notes' => 'Feed shortfall from the daily requirement (Feed-to-True-Cost)', 'created_by' => $request->user()->name]);
            foreach ($data['lines'] as $l) {
                $order->lines()->create(['kind' => 'feed', 'item_id' => $l['feed_item_id'], 'qty' => $l['qty'], 'rate_minor' => FeedItem::find($l['feed_item_id'])->cost_per_kg_minor]);
            }

            return $order;
        });
        Farm::log($request->user(), "Draft purchase order {$order->po_no} for feed shortfall", '/purchase');

        return back()->with('success', "{$order->po_no} drafted. Confirm it on Purchase Orders, then receive the goods there.");
    }

    /** Poor response → review intake, ration and illness (and adjust the plan). */
    public function review(Request $request, Pen $pen): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['ceo', 'manager', 'vet', 'feeding'], true), 403);
        $data = $request->validate(['issues' => ['nullable', 'string', 'max:400']]);
        abort_if(Task::where('pen_id', $pen->id)->where('title', "Feed response review: {$pen->fullName()}")->whereIn('status', Task::OPEN)->exists(), 422, 'A review for this PEN is already open.');
        foreach (['Feeding' => 'Check intake and refusals, and adjust the ration plan if needed.', 'Veterinary' => 'Check the PEN for illness or parasites that would explain the poor response.'] as $dept => $what) {
            Task::create(['title' => "Feed response review: {$pen->fullName()}", 'type' => $dept === 'Feeding' ? 'feeding' : 'health', 'priority' => 'medium', 'status' => 'open',
                'due_on' => now()->addDays(2)->toDateString(), 'pen_id' => $pen->id, 'department_id' => Department::where('name', $dept)->value('id'),
                'notes' => mb_substr(($data['issues'] ?? '').' '.$what, 0, 500)]);
        }
        Farm::log($request->user(), "Asked for a feed response review of {$pen->fullName()}", '/feed-cost');

        return back()->with('success', "Review tasks created for the Feeding and Veterinary teams ({$pen->fullName()}).");
    }

    public function settings(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['ceo', 'manager', 'vet'], true), 403);
        $data = $request->validate(['fcr_alert' => ['required', 'numeric', 'between:2,30']], [], ['fcr_alert' => 'feed conversion alert']);
        Organization::whereKey(Tenant::current())->update($data);

        return back()->with('success', 'Feed conversion alert saved.');
    }
}
