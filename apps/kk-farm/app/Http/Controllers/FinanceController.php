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
            'goats' => \App\Models\Animal::active()->orderBy('farm_number')->get(['id', 'farm_number']),
            'pending' => (float) LedgerEntry::where('direction', 'out')->whereIn('payment_status', ['pending', 'partial'])->whereDoesntHave('reversal')->whereNull('reverses_id')->sum('amount'),
            'entries' => LedgerEntry::with(['reversal', 'animal'])->orderByDesc('occurred_on')->orderByDesc('id')->limit(60)->get()->map(fn (LedgerEntry $e) => [
                'id' => $e->id, 'direction' => $e->direction, 'category' => $e->category, 'amount' => $e->amount, 'on' => $e->occurred_on->format('d/m/Y'), 'counterparty' => $e->counterparty,
                'description' => $e->description, 'by' => $e->recorded_by, 'reversed' => (bool) $e->reversal, 'isReversal' => (bool) $e->reverses_id,
                'payment' => $e->payment_status, 'paidBy' => $e->paid_by, 'reference' => $e->reference, 'goatId' => $e->animal_id, 'goat' => $e->animal?->farm_number,
            ]),
            'sales' => \App\Models\Sale::with('animal')->orderByDesc('sold_on')->orderByDesc('id')->limit(100)->get()->map(fn (\App\Models\Sale $s) => [
                'id' => $s->id, 'on' => $s->sold_on->format('d/m/Y'), 'category' => \App\Models\Sale::CATEGORIES[$s->category] ?? $s->category, 'goatId' => $s->animal_id,
                'what' => $s->animal?->farm_number ?? $s->item, 'buyer' => $s->buyer, 'gross' => $s->gross_minor / 100, 'fee' => $s->fee_minor / 100, 'net' => $s->netMinor() / 100,
                'receipt' => $s->receipt_ref, 'by' => $s->recorded_by,
            ]),
            // D-083: accounts the entry form picks from.
            'accounts' => \App\Support\Accounting::balances()->map(fn ($r) => $r['account'])->where('active', true)->whereIn('type', ['income', 'expense', 'asset'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => $a->label(), 'type' => $a->type, 'money' => $a->is_money])->values(),
            // The workbook's expense categories plus any category already used (feed and medicine purchases, sales).
            'categoryOptions' => collect(['Feed Purchase', 'Medicine', 'Veterinary', 'Labor', 'Electricity', 'Maintenance', 'Equipment', 'Transport', 'Utilities', 'Animal purchase', 'Animal sale', 'Product sale', 'Other'])
                ->merge(LedgerEntry::distinct()->pluck('category'))->unique()->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['in', 'out'])], 'amount' => ['required', 'numeric', 'min:1', 'max:100000000'],
            // D-083: the income or expense account; the older free-text category still works and finds or makes its account.
            'account_id' => ['nullable', 'required_without:category', Rule::exists('accounts', 'id')->whereIn('type', ['income', 'expense', 'asset'])],
            'category' => ['nullable', 'required_without:account_id', 'string', 'max:40'],
            'money_account_id' => ['nullable', Rule::exists('accounts', 'id')->where('is_money', true)],
            'counterparty' => ['nullable', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:200'], 'occurred_on' => ['required', 'date', 'before_or_equal:today'],
            // D-078: workbook "Expenses"
            'payment_status' => ['nullable', Rule::in(['paid', 'pending', 'partial'])], 'paid_by' => ['nullable', 'string', 'max:80'],
            'paid_now' => ['nullable', 'required_if:payment_status,partial', 'numeric', 'gt:0', 'lt:amount'],
            'reference' => ['nullable', 'string', 'max:60'], 'animal_id' => ['nullable', 'exists:animals,id'],
        ], [], ['paid_now' => 'amount paid now', 'account_id' => 'account']);
        $data['payment_status'] = $data['direction'] === 'in' ? 'paid' : ($data['payment_status'] ?? 'paid');
        if ($data['payment_status'] !== 'partial') {
            unset($data['paid_now']);
        }
        if (! empty($data['account_id'])) {
            $account = \App\Models\Account::findOrFail($data['account_id']);
            abort_if(($data['direction'] === 'in') !== ($account->type === 'income'), 422, 'Pick an income account for money in and an expense account for money out.');
            $data['category'] = $account->category ?? $account->name;
        }

        DB::transaction(fn () => LedgerEntry::create($data + ['recorded_by' => $request->user()->name])); // posts the journal (D-083)
        Farm::log($request->user(), "Recorded PKR {$data['amount']} ({$data['category']})", '/finance', 'finance');

        return back()->with('success', 'Entry recorded and posted to the books.');
    }

    public function reverse(Request $request, LedgerEntry $entry): RedirectResponse
    {
        abort_if($entry->reversal()->exists() || $entry->reverses_id, 422, 'This entry cannot be reversed again.');

        DB::transaction(fn () => LedgerEntry::create([
            'direction' => $entry->direction === 'in' ? 'out' : 'in', 'category' => $entry->category, 'amount' => $entry->amount, 'occurred_on' => now(),
            'counterparty' => $entry->counterparty, 'description' => "Reversal of entry #{$entry->id}", 'shed_id' => $entry->shed_id, 'animal_id' => $entry->animal_id,
            'reverses_id' => $entry->id, 'recorded_by' => $request->user()->name, 'account_id' => $entry->account_id, 'payment_status' => 'paid',
        ])); // D-083: posts the mirror image of every journal the original made
        Farm::log($request->user(), "Reversed ledger entry #{$entry->id}", '/finance', 'finance');

        return back()->with('success', 'Reversing entry added. The original stays on record.');
    }

    /** D-078/D-083: a pending or partly paid expense is settled: debit payable, credit cash or bank for what is still owed. */
    public function markPaid(Request $request, LedgerEntry $entry): RedirectResponse
    {
        abort_unless($entry->direction === 'out' && $entry->payment_status !== 'paid' && ! $entry->reversal()->exists() && ! $entry->reverses_id, 422);
        $data = $request->validate(['paid_by' => ['nullable', 'string', 'max:80'], 'money_account_id' => ['nullable', Rule::exists('accounts', 'id')->where('is_money', true)]]);
        DB::transaction(function () use ($entry, $data, $request) {
            \App\Support\Accounting::pay($entry, $data['money_account_id'] ?? null, $request->user()->name);
            $entry->update(['payment_status' => 'paid', 'paid_by' => $data['paid_by'] ?? $request->user()->name]);
        });
        Farm::log($request->user(), "Paid: {$entry->category} PKR ".number_format((float) $entry->amount).($entry->counterparty ? " to {$entry->counterparty}" : ''), '/finance', 'finance');

        return back()->with('success', 'Marked as paid and posted to the books.');
    }

    /** D-076: a product sale (milk, manure…) or another sale without a goat. Goat sales come from a movement. */
    public function sale(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sold_on' => ['required', 'date', 'before_or_equal:today'], 'category' => ['required', Rule::in(['product', 'other'])],
            'item' => ['required', 'string', 'max:120'], 'buyer' => ['nullable', 'string', 'max:120'],
            'gross' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'fee' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'receipt_ref' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:500'],
            'money_account_id' => ['nullable', Rule::exists('accounts', 'id')->where('is_money', true)],
        ], [], ['gross' => 'price', 'fee' => 'fee or discount']);
        $sale = \App\Support\Movements::sale($data, $request->user());
        Farm::log($request->user(), "Sale: {$data['item']} PKR ".number_format($sale->netMinor() / 100), '/finance', 'finance');

        return back()->with('success', 'Sale recorded: PKR '.number_format($sale->netMinor() / 100).' net.');
    }
}
