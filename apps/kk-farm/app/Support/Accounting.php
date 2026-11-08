<?php

namespace App\Support;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * D-083: double-entry bookkeeping. Every money event becomes one journal entry whose debits equal its credits.
 * Amounts are integer paisa. Journals are never changed: a correction is a reversing journal.
 */
final class Accounting
{
    /** code, name, type, system key, older ledger category, cash/bank */
    public const DEFAULT_ACCOUNTS = [
        ['1000', 'Cash in hand', 'asset', 'cash', null, true],
        ['1010', 'Bank account', 'asset', 'bank', null, true],
        ['1100', 'Accounts receivable', 'asset', 'receivable', null, false],
        ['2000', 'Accounts payable', 'liability', 'payable', null, false],
        ['3000', "Owner's capital", 'equity', 'capital', null, false],
        ['3100', "Owner's drawings", 'equity', 'drawings', null, false],
        ['3200', 'Retained earnings', 'equity', 'retained', null, false],
        ['4000', 'Sales - goats', 'income', null, 'Animal sale', false],
        ['4010', 'Sales - milk and products', 'income', null, 'Product sale', false],
        ['4090', 'Other income', 'income', 'other_income', null, false],
        ['5000', 'Feed', 'expense', null, 'Feed Purchase', false],
        ['5010', 'Medicine', 'expense', null, 'Medicine', false],
        ['5020', 'Veterinary services', 'expense', null, 'Veterinary', false],
        ['5030', 'Labour and wages', 'expense', null, 'Labor', false],
        ['5040', 'Electricity', 'expense', null, 'Electricity', false],
        ['5050', 'Repairs and maintenance', 'expense', null, 'Maintenance', false],
        ['5060', 'Equipment', 'expense', null, 'Equipment', false],
        ['5070', 'Transport', 'expense', null, 'Transport', false],
        ['5080', 'Utilities', 'expense', null, 'Utilities', false],
        ['5090', 'Goat purchases', 'expense', null, 'Animal purchase', false],
        ['5100', 'Sales fees and discounts', 'expense', 'fees', null, false],
        ['5900', 'Other expenses', 'expense', 'other_expense', null, false],
    ];

    /** The standard farm chart, once per farm. */
    public static function seed(): void
    {
        if (Account::exists()) {
            return;
        }
        foreach (self::DEFAULT_ACCOUNTS as [$code, $name, $type, $key, $category, $money]) {
            Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'system_key' => $key, 'category' => $category, 'is_money' => $money]);
        }
    }

    public static function account(string $key): Account
    {
        self::seed();

        return Account::where('system_key', $key)->firstOrFail();
    }

    /** The income or expense account for an older-style ledger category; a new category gets its own account. */
    public static function categoryAccount(string $category, string $direction): Account
    {
        self::seed();
        if (strcasecmp($category, 'Other') === 0) {
            return self::account($direction === 'in' ? 'other_income' : 'other_expense');
        }
        $types = $direction === 'in' ? ['income'] : ['expense', 'asset'];
        $found = Account::whereIn('type', $types)->where(fn ($q) => $q->whereRaw('lower(category) = lower(?)', [$category])->orWhereRaw('lower(name) = lower(?)', [$category]))->orderBy('code')->first();
        if ($found) {
            return $found;
        }
        $type = $direction === 'in' ? 'income' : 'expense';

        return Account::create(['code' => self::nextCode($type), 'name' => $category, 'type' => $type, 'category' => $category]);
    }

    public static function nextCode(string $type): string
    {
        $base = ['asset' => 1000, 'liability' => 2000, 'equity' => 3000, 'income' => 4000, 'expense' => 5000][$type];
        $max = Account::whereRaw("code ~ '^[0-9]+$'")->get(['code'])->map(fn ($a) => (int) $a->code)->filter(fn ($c) => $c >= $base && $c < $base + 1000)->max();

        return (string) min($base + 999, ($max ?? $base - 10) + 10);
    }

    /**
     * Post one balanced journal entry.
     *
     * @param  array<int, array{account_id: int, debit?: int, credit?: int, animal_id?: ?string, counterparty?: ?string}>  $lines  amounts in paisa
     */
    public static function post(string $date, string $memo, string $source, array $lines, string $by, array $meta = []): JournalEntry
    {
        $lines = array_values(array_filter($lines, fn ($l) => ($l['debit'] ?? 0) > 0 || ($l['credit'] ?? 0) > 0));
        $debit = array_sum(array_map(fn ($l) => (int) ($l['debit'] ?? 0), $lines));
        $credit = array_sum(array_map(fn ($l) => (int) ($l['credit'] ?? 0), $lines));
        foreach ($lines as $l) {
            if (($l['debit'] ?? 0) > 0 && ($l['credit'] ?? 0) > 0) {
                throw ValidationException::withMessages(['lines' => 'A line is either a debit or a credit, not both.']);
            }
        }
        if (count($lines) < 2 || $debit === 0) {
            throw ValidationException::withMessages(['lines' => 'A journal needs at least one debit and one credit.']);
        }
        if ($debit !== $credit) {
            throw ValidationException::withMessages(['lines' => sprintf('Debits (PKR %s) and credits (PKR %s) must be equal.', number_format($debit / 100, 2), number_format($credit / 100, 2))]);
        }

        return DB::transaction(function () use ($date, $memo, $source, $lines, $by, $meta) {
            DB::select('select pg_advisory_xact_lock(hashtext(coalesce(app_current_org()::text, \'\')))');
            $entry = JournalEntry::create([
                'entry_no' => (int) JournalEntry::max('entry_no') + 1, 'posted_on' => $date, 'memo' => mb_substr($memo, 0, 200), 'source' => $source,
                'ledger_entry_id' => $meta['ledger_entry_id'] ?? null, 'reverses_id' => $meta['reverses_id'] ?? null, 'reference' => $meta['reference'] ?? null, 'recorded_by' => $by,
            ]);
            foreach ($lines as $l) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id, 'account_id' => $l['account_id'], 'debit_minor' => (int) ($l['debit'] ?? 0), 'credit_minor' => (int) ($l['credit'] ?? 0),
                    'animal_id' => $l['animal_id'] ?? null, 'counterparty' => isset($l['counterparty']) ? mb_substr($l['counterparty'], 0, 80) : null,
                ]);
            }

            return $entry;
        });
    }

    /** The journal for a ledger entry: an expense, income or sale, or the reversal of an earlier one. Safe to call twice. */
    public static function postLedger(LedgerEntry $e, ?string $by = null): void
    {
        if (JournalEntry::where('ledger_entry_id', $e->id)->exists()) {
            return;
        }
        $by ??= $e->recorded_by;
        $date = $e->occurred_on->toDateString();

        if ($e->reverses_id) {
            JournalEntry::with('lines')->where('ledger_entry_id', $e->reverses_id)->whereNull('reverses_id')->whereDoesntHave('reversal')->orderBy('id')->get()
                ->each(fn (JournalEntry $j) => self::reverse($j, $date, $by, "Reversal of entry #{$e->reverses_id}", $e->id));

            return;
        }

        $amount = (int) round($e->amount * 100);
        $account = $e->account_id ? Account::findOrFail($e->account_id) : self::categoryAccount($e->category, $e->direction);
        $money = $e->money_account_id ?: self::account('cash')->id;
        $tag = ['animal_id' => $e->animal_id, 'counterparty' => $e->counterparty];
        $memo = trim($e->category.($e->description ? ": {$e->description}" : '').($e->counterparty ? " · {$e->counterparty}" : ''));
        $meta = ['ledger_entry_id' => $e->id, 'reference' => $e->reference];

        if ($e->direction === 'in') {
            $fee = (int) round(($e->fee ?? 0) * 100);
            $lines = [
                ['account_id' => $money, 'debit' => $amount] + $tag,
                ['account_id' => self::account('fees')->id, 'debit' => $fee] + $tag,
                ['account_id' => $account->id, 'credit' => $amount + $fee] + $tag,
            ];
            self::post($date, $memo, in_array($e->category, ['Animal sale', 'Product sale'], true) ? 'sale' : 'income', $lines, $by, $meta);

            return;
        }

        $paid = match ($e->payment_status ?? 'paid') {
            'pending' => 0,
            'partial' => max(0, min($amount, (int) round(($e->paid_now ?? 0) * 100))),
            default => $amount,
        };
        self::post($date, $memo, 'expense', [
            ['account_id' => $account->id, 'debit' => $amount] + $tag,
            ['account_id' => $money, 'credit' => $paid] + $tag,
            ['account_id' => self::account('payable')->id, 'credit' => $amount - $paid] + $tag,
        ], $by, $meta);
    }

    /** What is still owed to the vendor for an expense, in paisa. */
    public static function owedMinor(LedgerEntry $e): int
    {
        return (int) JournalLine::whereHas('entry', fn ($q) => $q->where('ledger_entry_id', $e->id))
            ->where('account_id', self::account('payable')->id)->sum(DB::raw('credit_minor::bigint - debit_minor::bigint'));
    }

    /** Settle what is still owed on an expense: debit payable, credit cash or bank. */
    public static function pay(LedgerEntry $e, ?int $moneyAccountId, string $by, ?string $date = null): ?JournalEntry
    {
        $owed = self::owedMinor($e);
        if ($owed <= 0) {
            return null;
        }
        $tag = ['animal_id' => $e->animal_id, 'counterparty' => $e->counterparty];

        return self::post($date ?? now()->toDateString(), "Payment: {$e->category}".($e->counterparty ? " · {$e->counterparty}" : ''), 'payment', [
            ['account_id' => self::account('payable')->id, 'debit' => $owed] + $tag,
            ['account_id' => $moneyAccountId ?: self::account('cash')->id, 'credit' => $owed] + $tag,
        ], $by, ['ledger_entry_id' => $e->id, 'reference' => $e->reference]);
    }

    /** The mirror image of a journal: every debit becomes a credit and the other way round. */
    public static function reverse(JournalEntry $j, string $date, string $by, ?string $memo = null, ?int $ledgerEntryId = null): JournalEntry
    {
        if ($j->reverses_id || $j->reversal()->exists()) {
            throw ValidationException::withMessages(['journal' => "Journal #{$j->entry_no} cannot be reversed again."]);
        }
        $lines = $j->lines->map(fn (JournalLine $l) => ['account_id' => $l->account_id, 'debit' => $l->credit_minor, 'credit' => $l->debit_minor, 'animal_id' => $l->animal_id, 'counterparty' => $l->counterparty])->all();

        return self::post($date, $memo ?? "Reversal of journal #{$j->entry_no}", 'reversal', $lines, $by, ['reverses_id' => $j->id, 'ledger_entry_id' => $ledgerEntryId, 'reference' => $j->reference]);
    }

    /**
     * Debit and credit totals per account between two dates (inclusive), every account in the chart.
     *
     * @return Collection<int, array{account: Account, debit: int, credit: int, balance: int}> balance on the account's normal side
     */
    public static function balances(?string $to = null, ?string $from = null): Collection
    {
        self::seed();
        $sums = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($from, fn ($q) => $q->where('journal_entries.posted_on', '>=', $from))
            ->when($to, fn ($q) => $q->where('journal_entries.posted_on', '<=', $to))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, sum(debit_minor)::bigint as d, sum(credit_minor)::bigint as c')->get()->keyBy('account_id');

        return Account::orderBy('code')->get()->map(function (Account $a) use ($sums) {
            $d = (int) ($sums[$a->id]->d ?? 0);
            $c = (int) ($sums[$a->id]->c ?? 0);

            return ['account' => $a, 'debit' => $d, 'credit' => $c, 'balance' => $a->debitNormal() ? $d - $c : $c - $d];
        });
    }

    /** @return array{rows: array, debit: float, credit: float, balanced: bool} */
    public static function trialBalance(string $asOf): array
    {
        $rows = self::balances($asOf)->filter(fn ($r) => $r['debit'] || $r['credit'])->map(fn ($r) => [
            'id' => $r['account']->id, 'code' => $r['account']->code, 'name' => $r['account']->name, 'type' => $r['account']->type,
            'debit' => max(0, $r['debit'] - $r['credit']) / 100, 'credit' => max(0, $r['credit'] - $r['debit']) / 100,
        ])->values();

        return ['rows' => $rows->all(), 'debit' => round($rows->sum('debit'), 2), 'credit' => round($rows->sum('credit'), 2), 'balanced' => round($rows->sum('debit'), 2) === round($rows->sum('credit'), 2)];
    }

    public static function profitAndLoss(string $from, string $to): array
    {
        $b = self::balances($to, $from);
        $rows = fn (string $type) => $b->filter(fn ($r) => $r['account']->type === $type && $r['balance'] !== 0)
            ->map(fn ($r) => ['id' => $r['account']->id, 'code' => $r['account']->code, 'name' => $r['account']->name, 'amount' => round($r['balance'] / 100, 2)])->values()->all();
        $income = round($b->filter(fn ($r) => $r['account']->type === 'income')->sum('balance') / 100, 2);
        $expense = round($b->filter(fn ($r) => $r['account']->type === 'expense')->sum('balance') / 100, 2);

        return ['income' => $rows('income'), 'expenses' => $rows('expense'), 'totalIncome' => $income, 'totalExpenses' => $expense, 'net' => round($income - $expense, 2)];
    }

    /** Assets = liabilities + equity, with the profit not yet closed into retained earnings shown inside equity. */
    public static function balanceSheet(string $asOf): array
    {
        $b = self::balances($asOf);
        $rows = fn (string $type) => $b->filter(fn ($r) => $r['account']->type === $type && $r['balance'] !== 0)
            ->map(fn ($r) => ['id' => $r['account']->id, 'code' => $r['account']->code, 'name' => $r['account']->name, 'amount' => round($r['balance'] / 100, 2)])->values()->all();
        $total = fn (string $type) => round($b->filter(fn ($r) => $r['account']->type === $type)->sum('balance') / 100, 2);
        $profit = round($total('income') - $total('expense'), 2);
        $assets = round($total('asset'), 2);
        $liabilities = round($total('liability'), 2);
        $equity = round($total('equity') + $profit, 2);

        return [
            'assets' => $rows('asset'), 'liabilities' => $rows('liability'), 'equity' => $rows('equity'), 'profit' => $profit,
            'totalAssets' => $assets, 'totalLiabilities' => $liabilities, 'totalEquity' => $equity, 'balanced' => abs($assets - ($liabilities + $equity)) < 0.005,
        ];
    }

    /** One account's lines with a running balance, from an opening balance at $from. */
    public static function ledger(Account $account, string $from, string $to): array
    {
        $sign = $account->debitNormal() ? 1 : -1;
        $before = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('account_id', $account->id)->where('posted_on', '<', $from)->selectRaw('coalesce(sum(debit_minor::bigint - credit_minor::bigint), 0) as n')->value('n');
        $running = $sign * (int) $before;
        $opening = $running;
        $lines = JournalLine::with(['entry', 'animal'])->where('account_id', $account->id)
            ->whereHas('entry', fn ($q) => $q->whereBetween('posted_on', [$from, $to]))->get()
            ->sortBy(fn ($l) => [$l->entry->posted_on->toDateString(), $l->entry->entry_no])->values()
            ->map(function (JournalLine $l) use (&$running, $sign) {
                $running += $sign * ($l->debit_minor - $l->credit_minor);

                return ['entryId' => $l->journal_entry_id, 'no' => $l->entry->entry_no, 'on' => $l->entry->posted_on->format('j M Y'), 'memo' => $l->entry->memo,
                    'party' => $l->counterparty, 'goat' => $l->animal?->farm_number, 'goatId' => $l->animal_id,
                    'debit' => $l->debit_minor / 100, 'credit' => $l->credit_minor / 100, 'balance' => $running / 100];
            });

        return ['opening' => $opening / 100, 'closing' => $running / 100, 'lines' => $lines->all()];
    }
}
