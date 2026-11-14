<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\Accounting;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** D-083: the double-entry books: journal, general ledger, trial balance, profit & loss, balance sheet, chart of accounts. */
class BooksController extends Controller
{
    public function index(Request $request): Response
    {
        Accounting::seed();
        $q = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'account' => ['nullable', 'integer']]);
        $to = isset($q['to']) ? Carbon::parse($q['to'])->toDateString() : now()->toDateString();
        $from = isset($q['from']) ? Carbon::parse($q['from'])->toDateString() : Carbon::parse($to)->startOfYear()->toDateString();
        $account = Account::find($q['account'] ?? null) ?? Account::where('system_key', 'cash')->first();

        // D-243: each tab's records only go to people who may open that tab; the headline figures stay for everyone here
        $tab = fn (string $t) => \App\Support\Pages::allows($request->user(), '/finance/books', ['tab' => $t]);
        $trial = Accounting::trialBalance($to);
        $pl = Accounting::profitAndLoss($from, $to);
        $balance = Accounting::balanceSheet($to);
        $ledger = ['account' => ['id' => $account->id, 'label' => $account->label(), 'type' => $account->type]] + Accounting::ledger($account, $from, $to);
        if (! $tab('trial')) { $trial['rows'] = []; }
        if (! $tab('pl')) { $pl['income'] = []; $pl['expenses'] = []; }
        if (! $tab('balance')) { $balance['assets'] = []; $balance['liabilities'] = []; $balance['equity'] = []; }
        if (! $tab('ledger')) { $ledger['lines'] = []; }
        $allAccounts = $tab('accounts') || $tab('journal') || $tab('ledger');

        return Inertia::render('Books', [
            'from' => $from, 'to' => $to,
            'journal' => ! $tab('journal') ? [] : JournalEntry::with(['lines.account', 'lines.animal', 'reversal'])->whereBetween('posted_on', [$from, $to])
                ->orderByDesc('posted_on')->orderByDesc('entry_no')->limit(200)->get()->map(fn (JournalEntry $j) => [
                    'id' => $j->id, 'no' => $j->entry_no, 'on' => $j->posted_on->format('d/m/Y'), 'memo' => $j->memo, 'source' => JournalEntry::SOURCES[$j->source] ?? $j->source,
                    'reference' => $j->reference, 'by' => $j->recorded_by, 'reversed' => (bool) $j->reversal, 'isReversal' => (bool) $j->reverses_id,
                    'lines' => $j->lines->map(fn (JournalLine $l) => ['account' => $l->account->label(), 'accountId' => $l->account_id, 'debit' => $l->debit_minor / 100, 'credit' => $l->credit_minor / 100, 'goat' => $l->animal?->farm_number])->all(),
                ]),
            'trial' => $trial,
            'pl' => $pl,
            'balance' => $balance,
            'ledger' => $ledger,
            'accounts' => Accounting::balances($to)->filter(fn ($r) => $allAccounts || $r['account']->is_money || $r['account']->code === '2000')->map(fn ($r) => [
                'id' => $r['account']->id, 'code' => $r['account']->code, 'name' => $r['account']->name, 'type' => $r['account']->type, 'money' => $r['account']->is_money,
                'active' => $r['account']->active, 'system' => (bool) $r['account']->system_key, 'balance' => $r['balance'] / 100, 'used' => $r['debit'] > 0 || $r['credit'] > 0,
            ])->values(),
            'types' => Account::TYPES,
        ]);
    }

    /** A manual journal: owner's capital, a transfer between cash and bank, drawings, opening balances, corrections. */
    public function storeJournal(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'posted_on' => ['required', 'date', 'before_or_equal:today'], 'memo' => ['required', 'string', 'max:200'], 'reference' => ['nullable', 'string', 'max:60'],
            'opening' => ['boolean'],
            'lines' => ['required', 'array', 'min:2', 'max:30'],
            'lines.*.account_id' => ['required', Rule::exists('accounts', 'id')->where('active', true)],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:100000000'], 'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'lines.*.counterparty' => ['nullable', 'string', 'max:80'],
        ], [], ['lines.*.account_id' => 'account']);
        $lines = array_map(fn ($l) => ['account_id' => (int) $l['account_id'], 'debit' => (int) round(((float) ($l['debit'] ?? 0)) * 100), 'credit' => (int) round(((float) ($l['credit'] ?? 0)) * 100), 'counterparty' => $l['counterparty'] ?? null], $data['lines']);
        $j = Accounting::post($data['posted_on'], $data['memo'], ! empty($data['opening']) ? 'opening' : 'manual', $lines, $request->user()->name, ['reference' => $data['reference'] ?? null]);
        Farm::log($request->user(), "Posted journal #{$j->entry_no}: {$data['memo']}", '/finance/books', 'finance');

        return back()->with('success', "Journal #{$j->entry_no} posted.");
    }

    public function reverseJournal(Request $request, JournalEntry $journal): RedirectResponse
    {
        abort_if($journal->ledger_entry_id !== null, 422, 'This journal came from a ledger entry: reverse the entry on the Finance page instead.');
        $r = Accounting::reverse($journal->load('lines'), now()->toDateString(), $request->user()->name);
        Farm::log($request->user(), "Reversed journal #{$journal->entry_no}", '/finance/books', 'finance');

        return back()->with('success', "Journal #{$r->entry_no} reverses #{$journal->entry_no}. Both stay on record.");
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'type' => ['required', Rule::in(array_keys(Account::TYPES))],
            'code' => ['nullable', 'string', 'max:10', 'regex:/^[0-9A-Za-z-]+$/', Rule::unique('accounts', 'code')->where('org_id', \App\Support\Tenant::current())],
            'is_money' => ['boolean'], 'notes' => ['nullable', 'string', 'max:200'],
        ]);
        abort_if(! empty($data['is_money']) && $data['type'] !== 'asset', 422, 'Only an asset account can be a cash or bank account.');
        $a = Account::create($data + ['code' => $data['code'] ?? Accounting::nextCode($data['type'])]);
        Farm::log($request->user(), "Added account {$a->label()}", '/finance/books', 'finance');

        return back()->with('success', "Account {$a->label()} added.");
    }

    /** Name, notes and active can change; the type cannot once the account has postings. */
    public function updateAccount(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'active' => ['boolean'], 'notes' => ['nullable', 'string', 'max:200']]);
        abort_if($account->system_key && array_key_exists('active', $data) && ! $data['active'], 422, 'A system account cannot be switched off.');
        $account->update($data);

        return back()->with('success', 'Account saved.');
    }
}
