<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\RfidAssignment;
use App\Models\Sale;
use App\Models\SaleOrder;
use App\Models\SaleOrderLine;
use App\Models\SaleScan;
use App\Support\Accounting;
use App\Support\Classification;
use App\Support\Farm;
use App\Support\Feed;
use App\Support\Growth;
use App\Support\HerdValue;
use App\Support\Movements;
use App\Support\SaleCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-194: V3 cross-app journey E Sale-to-Cash & Exit (flow 28):
 * breeding / finishing goat selected → review animal ID, health and ownership (not clear: resolve withdrawal / missing tag /
 * hold) → weight, breed, pedigree and price quote → approve commercial terms and customer (not the person who quoted,
 * BR-027) → reserve animals; dispatch checklist → scan RFID and confirm the loaded headcount (mismatch: stop for a missing or
 * substituted animal, rescan) → generate invoice and payment / receivable → dispatch and transfer lifecycle status (exit) →
 * post cost basis / accounting impact → review realised profit and buyer record.
 * Key controls: no sale during withdrawal, RFID at loading, no duplicate exit, approved pricing.
 */
class SaleOrderController extends Controller
{
    private function approver(Request $request): bool
    {
        return in_array($request->user()->role, ['ceo', 'manager'], true);
    }

    public function index(Request $request): Response
    {
        $orders = SaleOrder::with(['customer', 'lines.animal.breed', 'lines.sale', 'scans'])->where(fn ($q) => $q->whereIn('status', ['quote', 'reserved'])->orWhere('updated_at', '>=', now()->subDays(120)))
            ->orderByRaw("case status when 'quote' then 0 when 'reserved' then 1 when 'dispatched' then 2 else 3 end")->orderByDesc('id')->get();
        $sold = SaleOrderLine::with('order')->where('status', 'sold')->whereHas('order', fn ($q) => $q->where('dispatched_on', '>=', now()->subDays(90)->toDateString()))->get();
        $scans = SaleScan::where('created_at', '>=', now()->subDays(90))->get();
        $credit = SaleOrder::where('terms', 'credit')->whereNotNull('collected_at')->where('dispatched_on', '>=', now()->subDays(180)->toDateString())->get();
        $onOrder = SaleOrderLine::whereIn('status', SaleOrderLine::OPEN)->pluck('animal_id')->all();
        $unsold = Animal::active()->where(fn ($q) => $q->whereIn('animal_purpose', ['sale', 'meat', 'fattening'])->orWhere('kid_stage', 'sale_selected'))->whereNotIn('id', $onOrder)->count();
        $weights = Feed::latestWeights();
        $value = new HerdValue;
        $today = Feed::now()->startOfDay();

        return Inertia::render('SaleOrders', [
            'orders' => $orders->map(fn (SaleOrder $o) => [
                'id' => $o->id, 'no' => $o->order_no, 'customer' => $o->customer?->name ?? $o->buyer, 'customerId' => $o->customer_id, 'basis' => $o->basis, 'terms' => $o->terms,
                'due' => $o->due_on?->format('j M Y'), 'status' => $o->status, 'by' => $o->created_by, 'approvedBy' => $o->approved_by, 'invoice' => $o->invoice_no, 'notes' => $o->notes,
                'dispatched' => $o->dispatched_on?->format('j M Y'), 'collected' => $o->collected_at?->format('j M Y'), 'checklist' => $o->checklist ?? [],
                'owed' => $o->status === 'dispatched' && ! $o->collected_at ? round($o->lines->filter(fn ($l) => $l->sale)->sum(fn ($l) => ($e = LedgerEntry::find($l->sale->ledger_entry_id)) ? Accounting::receivableMinor($e) : 0) / 100) : 0,
                'total' => $o->lines->whereNotIn('status', ['removed'])->sum('price_minor') / 100,
                'errors' => $o->scans->whereNotIn('result', ['match'])->count(),
                'canApprove' => $o->status === 'quote' && $this->approver($request) && ($o->created_by !== $request->user()->name || $request->user()->role === 'ceo'),
                'lines' => $o->lines->map(fn (SaleOrderLine $l) => [
                    'id' => $l->id, 'goatId' => $l->animal_id, 'number' => $l->animal?->farm_number, 'breed' => $l->animal?->breed?->name, 'sex' => $l->animal?->sex,
                    'pedigree' => trim(($l->animal?->sire_tag ?? $l->animal?->sire?->farm_number ?? '').' × '.($l->animal?->dam_tag ?? $l->animal?->dam?->farm_number ?? ''), ' ×') ?: null,
                    'weight' => $l->weight_kg, 'price' => $l->price_minor / 100, 'market' => $l->market_minor !== null ? $l->market_minor / 100 : null, 'status' => $l->status, 'removed' => $l->removed_reason,
                    'cost' => $l->cost ? array_map(fn ($v) => $v / 100, $l->cost) : null, 'margin' => $l->cost ? ($l->price_minor - $l->cost['total']) / 100 : null,
                    'blockers' => in_array($l->status, ['quoted', 'reserved', 'loaded'], true) && $l->animal ? SaleCheck::blockers($l->animal, $o->id) : [],
                ])->values(),
            ]),
            'candidates' => Animal::with('breed')->active()->whereNotIn('id', $onOrder)->orderByRaw("case when animal_purpose in ('sale','meat','fattening') or kid_stage = 'sale_selected' then 0 else 1 end")->orderBy('farm_number')->get()
                ->map(fn (Animal $a) => ['id' => $a->id, 'number' => $a->farm_number, 'breed' => $a->breed?->name, 'sex' => $a->sex, 'purpose' => Classification::PURPOSES[$a->animal_purpose] ?? null,
                    'weight' => $weights[$a->id] ?? null, 'market' => ($r = $value->rateOn($today, $a->animal_purpose)) && isset($weights[$a->id]) ? round($weights[$a->id] * $r / 100) : null,
                    'blockers' => SaleCheck::blockers($a)]),
            'customers' => Customer::where('active', true)->orderBy('name')->get(['id', 'name']),
            'kpi' => [
                'margin' => $sold->whereNotNull('cost')->count() ? round($sold->whereNotNull('cost')->avg(fn ($l) => $l->price_minor - $l->cost['total']) / 100) : null,
                'dispatchError' => $scans->count() ? round($scans->where('result', '!=', 'match')->count() / $scans->count() * 100, 1) : null,
                'daysToCollect' => $credit->count() ? round($credit->avg(fn ($o) => $o->dispatched_on->diffInDays($o->collected_at)), 1) : null,
                'unsold' => $unsold, 'receivable' => round(SaleOrder::where('status', 'dispatched')->whereNull('collected_at')->where('terms', 'credit')->with('lines.sale')->get()
                    ->sum(fn ($o) => $o->lines->filter(fn ($l) => $l->sale)->sum(fn ($l) => ($e = LedgerEntry::find($l->sale->ledger_entry_id)) ? Accounting::receivableMinor($e) : 0)) / 100),
            ],
            'checklist' => SaleOrder::CHECKLIST,
            'statuses' => SaleOrder::STATUS,
            'canSell' => $request->user()->passes('manage_sales'), 'canApprove' => $this->approver($request),
        ]);
    }

    /** Weight, breed, pedigree and price quote. Every goat must be clear (ID, health, ownership) to be quoted. */
    public function quote(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sales'), 403);
        $data = $request->validate([
            'customer_id' => ['nullable', 'required_without:buyer', 'exists:customers,id'], 'buyer' => ['nullable', 'string', 'max:120'], 'basis' => ['required', Rule::in(['per_head', 'per_kg'])],
            'rate_per_kg' => ['nullable', 'required_if:basis,per_kg', 'numeric', 'gt:0', 'max:100000'], 'terms' => ['required', Rule::in(['cash', 'credit'])],
            'due_on' => ['nullable', 'required_if:terms,credit', 'date', 'after_or_equal:today'], 'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'], 'lines.*.animal_id' => ['required', 'distinct', 'exists:animals,id'], 'lines.*.price' => ['nullable', 'required_if:basis,per_head', 'numeric', 'gt:0', 'max:100000000'],
        ], ['customer_id.required_without' => 'Choose the customer (or type the buyer).', 'due_on.required_if' => 'A credit sale needs the date payment is due.'], ['rate_per_kg' => 'rate per KG']);
        $weights = Feed::latestWeights();
        $value = new HerdValue;
        $today = Feed::now()->startOfDay();
        $errors = [];
        $rows = [];
        foreach ($data['lines'] as $i => $l) {
            $a = Animal::findOrFail($l['animal_id']);
            if ($b = SaleCheck::blockers($a)) {
                $errors["lines.$i.animal_id"] = "{$a->farm_number}: ".implode('; ', $b).'. Resolve it first.';

                continue;
            }
            $w = $weights[$a->id] ?? null;
            if ($data['basis'] === 'per_kg' && ! $w) {
                $errors["lines.$i.animal_id"] = "{$a->farm_number} has no weight: weigh it to price per KG.";

                continue;
            }
            $price = $data['basis'] === 'per_kg' ? (int) round($w * $data['rate_per_kg'] * 100) : (int) round($l['price'] * 100);
            $rate = $value->rateOn($today, $a->animal_purpose);
            $rows[] = ['animal_id' => $a->id, 'weight_kg' => $w, 'price_minor' => $price, 'market_minor' => $rate && $w ? (int) round($w * $rate) : null];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $order = DB::transaction(function () use ($data, $rows, $request) {
            DB::statement('LOCK TABLE sale_orders IN SHARE ROW EXCLUSIVE MODE');
            $prefix = 'SO-'.now()->format('ym').'-';
            $order = SaleOrder::create(['order_no' => $prefix.str_pad((string) (SaleOrder::where('order_no', 'like', "$prefix%")->count() + 1), 3, '0', STR_PAD_LEFT),
                'customer_id' => $data['customer_id'] ?? null, 'buyer' => $data['buyer'] ?? null, 'basis' => $data['basis'], 'terms' => $data['terms'], 'due_on' => $data['due_on'] ?? null,
                'status' => 'quote', 'created_by' => $request->user()->name, 'notes' => $data['notes'] ?? null]);
            foreach ($rows as $r) {
                $order->lines()->create($r + ['status' => 'quoted']);
            }

            return $order;
        });
        Farm::log($request->user(), "Quoted sale order {$order->order_no}", '/sales/orders');

        return back()->with('success', "{$order->order_no} quoted. The owner or manager approves the terms and customer.");
    }

    /** Approve commercial terms and customer (not the person who quoted) → animals reserved. */
    public function approve(Request $request, SaleOrder $order): RedirectResponse
    {
        abort_unless($this->approver($request), 403, 'The owner or manager approves sale terms.');
        abort_unless($order->status === 'quote', 422, 'Only a quote is approved.');
        abort_if($order->created_by === $request->user()->name && $request->user()->role !== 'ceo', 403, 'Someone other than the person who quoted approves the price.');
        DB::transaction(function () use ($order, $request) {
            $order->update(['status' => 'reserved', 'approved_by' => $request->user()->name, 'approved_at' => now()]);
            $order->lines()->where('status', 'quoted')->update(['status' => 'reserved']);
            foreach ($order->lines()->with('animal')->get() as $l) {
                Farm::event($l->animal, 'sale', "Reserved for sale order {$order->order_no}", $request->user()->name);
            }
        });

        return back()->with('success', "{$order->order_no} approved. The goats are reserved; tick the dispatch checklist and scan them at loading.");
    }

    public function checklist(Request $request, SaleOrder $order): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sales') || $this->approver($request), 403);
        abort_unless($order->status === 'reserved', 422);
        $data = $request->validate(['done' => ['present', 'array'], 'done.*' => [Rule::in(array_keys(SaleOrder::CHECKLIST))]]);
        $order->update(['checklist' => array_values(array_unique($data['done']))]);

        return back()->with('success', 'Dispatch checklist saved.');
    }

    /** Scan RFID at loading. A goat not on the order (substituted), a repeat or an unknown tag stops loading. */
    public function scan(Request $request, SaleOrder $order): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sales') || $this->approver($request) || $request->user()->passes('scan'), 403);
        abort_unless($order->status === 'reserved', 422, 'Loading starts once the order is approved.');
        $data = $request->validate(['code' => ['required', 'string', 'max:80']]);
        $code = strtoupper(trim($data['code']));
        $animalId = RfidAssignment::whereNull('valid_to')->whereHas('tag', fn ($q) => $q->whereRaw('upper(tag_code) = ?', [$code]))->value('animal_id')
            ?? Animal::whereRaw('upper(farm_number) = ?', [$code])->value('id');
        $line = $animalId ? $order->lines()->where('animal_id', $animalId)->first() : null;
        $result = match (true) {
            ! $animalId => 'unknown', ! $line || $line->status === 'removed' => 'not_on_order', $line->status === 'loaded' => 'duplicate', default => 'match',
        };
        SaleScan::create(['order_id' => $order->id, 'code' => $code, 'result' => $result, 'scanned_by' => $request->user()->name]);
        if ($result !== 'match') {
            $msg = ['unknown' => "Tag {$code} is not a goat of this farm.", 'not_on_order' => 'This goat is NOT on the order: substituted animal. Take it off the vehicle.', 'duplicate' => 'This goat was already scanned: check the headcount.'][$result];
            throw ValidationException::withMessages(['code' => "STOP: {$msg} Rescan."]);
        }
        if ($b = SaleCheck::blockers($line->animal, $order->id)) {
            SaleScan::latest('id')->first()->update(['result' => 'blocked']);
            throw ValidationException::withMessages(['code' => "STOP: {$line->animal->farm_number}: ".implode('; ', $b).'.']);
        }
        $line->update(['status' => 'loaded', 'loaded_at' => now(), 'scanned_code' => $code]);
        $left = $order->lines()->where('status', 'reserved')->count();

        return back()->with('success', "{$line->animal->farm_number} loaded.".($left ? " {$left} still to load." : ' Headcount matches: dispatch.'));
    }

    /** A reserved goat that is missing or cannot go comes off the order with a reason. */
    public function remove(Request $request, SaleOrderLine $line): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sales') || $this->approver($request), 403);
        abort_unless(in_array($line->status, ['quoted', 'reserved', 'loaded'], true), 422);
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $line->update(['status' => 'removed', 'removed_reason' => $data['reason']]);

        return back()->with('success', 'Taken off the order.');
    }

    /**
     * Invoice and dispatch: every remaining goat loaded by scan and the checklist complete. Each goat leaves through the
     * normal exit (withdrawal and holds checked again, one exit only), its sale posts once (paid, or owed on credit), the
     * cost basis is kept for the margin.
     */
    public function dispatch(Request $request, SaleOrder $order): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sales') || $this->approver($request), 403);
        abort_unless($order->status === 'reserved', 422, 'Only an approved order is dispatched.');
        $lines = $order->lines()->with('animal')->whereIn('status', ['reserved', 'loaded'])->get();
        abort_if($lines->isEmpty(), 422, 'No goats left on this order.');
        if ($lines->where('status', 'reserved')->isNotEmpty()) {
            throw ValidationException::withMessages(['dispatch' => 'Loaded headcount does not match: '.$lines->where('status', 'reserved')->map(fn ($l) => $l->animal->farm_number)->implode(', ').' not scanned. Scan them, or take them off the order.']);
        }
        if ($missing = array_diff(array_keys(SaleOrder::CHECKLIST), $order->checklist ?? [])) {
            throw ValidationException::withMessages(['dispatch' => 'Finish the dispatch checklist: '.implode(', ', array_map(fn ($k) => SaleOrder::CHECKLIST[$k], $missing)).'.']);
        }
        $data = $request->validate(['dispatched_on' => ['required', 'date', 'before_or_equal:today']]);
        $costs = SaleCheck::costBasis($lines->pluck('animal'));
        DB::transaction(function () use ($order, $lines, $data, $request, $costs) {
            DB::statement('LOCK TABLE sale_orders IN SHARE ROW EXCLUSIVE MODE');
            $invoice = 'INV-'.now()->format('ym').'-'.str_pad((string) (SaleOrder::whereNotNull('invoice_no')->where('invoice_no', 'like', 'INV-'.now()->format('ym').'-%')->count() + 1), 3, '0', STR_PAD_LEFT);
            $customer = $order->customer?->name ?? $order->buyer;
            foreach ($lines as $l) {
                $movement = Movements::exit($l->animal, 'sale', $request->user(), [
                    'moved_on' => $data['dispatched_on'], 'destination' => $customer, 'amount' => $l->price_minor / 100, 'sale_category' => 'live_goat', 'evidence_ref' => $invoice,
                    'reason' => "Sale order {$order->order_no}", 'payment_status' => $order->terms === 'credit' ? 'pending' : 'paid', 'customer_id' => $order->customer_id,
                ]);
                $sale = Sale::where('movement_id', $movement->id)->first();
                $l->update(['status' => 'sold', 'sale_id' => $sale?->id, 'cost' => $costs[$l->animal_id] ?? null]);
            }
            $order->update(['status' => 'dispatched', 'dispatched_on' => $data['dispatched_on'], 'dispatched_by' => $request->user()->name, 'invoice_no' => $invoice,
                'collected_at' => $order->terms === 'cash' ? now() : null]);
            Farm::log($request->user(), "Dispatched {$order->order_no} ({$invoice})", '/sales/orders');
        });

        return back()->with('success', "{$order->order_no} dispatched and invoiced".($order->terms === 'credit' ? ': the amount is owed by the customer until collected.' : ': paid.'));
    }

    /** The customer pays a credit invoice: Accounts receivable is cleared. */
    public function collect(Request $request, SaleOrder $order): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sales') || $this->approver($request), 403);
        abort_unless($order->status === 'dispatched' && ! $order->collected_at, 422, 'Nothing to collect.');
        $data = $request->validate(['collected_on' => ['required', 'date', 'before_or_equal:today'], 'money_account_id' => ['nullable', Rule::exists('accounts', 'id')->where('is_money', true)]]);
        DB::transaction(function () use ($order, $data, $request) {
            foreach ($order->lines()->with('sale')->where('status', 'sold')->get() as $l) {
                if ($l->sale && ($e = LedgerEntry::find($l->sale->ledger_entry_id))) {
                    Accounting::collect($e, $data['money_account_id'] ?? null, $request->user()->name, $data['collected_on']);
                    $e->update(['payment_status' => 'paid']);
                }
            }
            $order->update(['collected_at' => $data['collected_on']]);
        });

        return back()->with('success', "Payment for {$order->invoice_no} received.");
    }

    public function cancel(Request $request, SaleOrder $order): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sales') || $this->approver($request), 403);
        abort_unless(in_array($order->status, ['quote', 'reserved'], true), 422, 'A dispatched order is not cancelled.');
        DB::transaction(function () use ($order) {
            $order->update(['status' => 'cancelled']);
            $order->lines()->whereIn('status', SaleOrderLine::OPEN)->update(['status' => 'removed', 'removed_reason' => 'Order cancelled']);
        });

        return back()->with('success', "{$order->order_no} cancelled; the goats are free again.");
    }
}
