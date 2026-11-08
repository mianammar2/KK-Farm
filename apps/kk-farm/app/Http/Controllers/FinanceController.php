<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Ledger entries are append-only: a correction is a reversing entry, never an edit. */
class FinanceController extends Controller
{
    public function index(Request $request): Response
    {
        $yearStart = now()->subMonths(11)->startOfMonth();
        $monthStart = now()->startOfMonth();
        $sum = fn (string $direction, $from) => (float) LedgerEntry::where('direction', $direction)->where('occurred_on', '>=', $from)->sum('amount');

        return Inertia::render('Finance', [
            'summary' => [
                'monthIn' => $sum('in', $monthStart), 'monthOut' => $sum('out', $monthStart),
                'yearIn' => $sum('in', $yearStart), 'yearOut' => $sum('out', $yearStart),
            ],
            'monthly' => LedgerEntry::where('occurred_on', '>=', $yearStart)
                ->selectRaw("to_char(occurred_on, 'YYYY-MM') as month, sum(case when direction = 'in' then amount else 0 end)::float as income, sum(case when direction = 'out' then amount else 0 end)::float as spend")
                ->groupBy('month')->orderBy('month')->get(),
            'categories' => LedgerEntry::where('direction', 'out')->where('occurred_on', '>=', $yearStart)->groupBy('category')
                ->select('category as name', DB::raw('sum(amount)::float as value'))->orderByDesc('value')->get(),
            'entries' => LedgerEntry::with('reversal')->orderByDesc('occurred_on')->orderByDesc('id')->limit(60)->get()->map(fn (LedgerEntry $e) => [
                'id' => $e->id, 'direction' => $e->direction, 'category' => $e->category, 'amount' => $e->amount, 'on' => $e->occurred_on->format('j M Y'), 'counterparty' => $e->counterparty,
                'description' => $e->description, 'by' => $e->recorded_by, 'reversed' => (bool) $e->reversal, 'isReversal' => (bool) $e->reverses_id,
            ]),
            'sales' => \App\Models\Sale::with('animal')->orderByDesc('sold_on')->orderByDesc('id')->limit(100)->get()->map(fn (\App\Models\Sale $s) => [
                'id' => $s->id, 'on' => $s->sold_on->format('j M Y'), 'category' => \App\Models\Sale::CATEGORIES[$s->category] ?? $s->category, 'goatId' => $s->animal_id,
                'what' => $s->animal?->farm_number ?? $s->item, 'buyer' => $s->buyer, 'gross' => $s->gross_minor / 100, 'fee' => $s->fee_minor / 100, 'net' => $s->netMinor() / 100,
                'receipt' => $s->receipt_ref, 'by' => $s->recorded_by,
            ]),
            // The workbook's expense categories plus any category already used (feed and medicine purchases, sales).
            'categoryOptions' => collect(['Feed Purchase', 'Medicine', 'Veterinary', 'Labor', 'Electricity', 'Maintenance', 'Equipment', 'Transport', 'Utilities', 'Animal purchase', 'Animal sale', 'Product sale', 'Other'])
                ->merge(LedgerEntry::distinct()->pluck('category'))->unique()->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['in', 'out'])], 'category' => ['required', 'string', 'max:40'], 'amount' => ['required', 'numeric', 'min:1', 'max:100000000'], 'counterparty' => ['nullable', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:200'], 'occurred_on' => ['required', 'date', 'before_or_equal:today']]);

        LedgerEntry::create($data + ['recorded_by' => $request->user()->name]); // D-076: no longer filed under shed #1
        Farm::log($request->user(), "Recorded PKR {$data['amount']} ({$data['category']})", '/finance', 'finance');

        return back()->with('success', 'Entry recorded.');
    }

    public function reverse(Request $request, LedgerEntry $entry): RedirectResponse
    {
        abort_if($entry->reversal()->exists() || $entry->reverses_id, 422, 'This entry cannot be reversed again.');

        LedgerEntry::create([
            'direction' => $entry->direction === 'in' ? 'out' : 'in', 'category' => $entry->category, 'amount' => $entry->amount, 'occurred_on' => now(),
            'counterparty' => $entry->counterparty, 'description' => "Reversal of entry #{$entry->id}", 'shed_id' => $entry->shed_id, 'animal_id' => $entry->animal_id,
            'reverses_id' => $entry->id, 'recorded_by' => $request->user()->name,
        ]);
        Farm::log($request->user(), "Reversed ledger entry #{$entry->id}", '/finance', 'finance');

        return back()->with('success', 'Reversing entry added. The original stays on record.');
    }

    /** D-076: a product sale (milk, manure…) or another sale without a goat. Goat sales come from a movement. */
    public function sale(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sold_on' => ['required', 'date', 'before_or_equal:today'], 'category' => ['required', Rule::in(['product', 'other'])],
            'item' => ['required', 'string', 'max:120'], 'buyer' => ['nullable', 'string', 'max:120'],
            'gross' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'fee' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'receipt_ref' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['gross' => 'price', 'fee' => 'fee or discount']);
        $sale = \App\Support\Movements::sale($data, $request->user());
        Farm::log($request->user(), "Sale: {$data['item']} PKR ".number_format($sale->netMinor() / 100), '/finance', 'finance');

        return back()->with('success', 'Sale recorded: PKR '.number_format($sale->netMinor() / 100).' net.');
    }
}
