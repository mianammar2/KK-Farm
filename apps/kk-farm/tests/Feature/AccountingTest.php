<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\User;
use App\Support\Accounting;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-083: double-entry books. */
class AccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Books Farm', 'slug' => 'books-farm'])->id);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@books.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    /** @return array<string, array{0: int, 1: int}> account code => [debit, credit] in paisa for one ledger entry */
    private function lines(LedgerEntry $e): array
    {
        return JournalLine::with('account')->whereHas('entry', fn ($q) => $q->where('ledger_entry_id', $e->id))->get()
            ->groupBy(fn ($l) => $l->account->code)->map(fn ($g) => [(int) $g->sum('debit_minor'), (int) $g->sum('credit_minor')])->all();
    }

    private function code(string $key): int
    {
        return Accounting::account($key)->id;
    }

    public function test_a_paid_expense_debits_the_expense_and_credits_cash_or_bank(): void
    {
        $feed = Accounting::categoryAccount('Feed Purchase', 'out');
        $this->actingAs($this->user('finance'))->post('/finance/entries', ['direction' => 'out', 'account_id' => $feed->id, 'amount' => 12500, 'occurred_on' => now()->toDateString(), 'money_account_id' => $this->code('bank')])->assertSessionHasNoErrors();

        $e = LedgerEntry::sole();
        $this->assertSame('Feed Purchase', $e->category);
        $this->assertSame(['1010' => [0, 1250000], '5000' => [1250000, 0]], collect($this->lines($e))->sortKeys()->all());
        $this->assertTrue(Accounting::trialBalance(now()->toDateString())['balanced']);
    }

    public function test_a_partly_paid_expense_owes_the_rest_until_it_is_paid(): void
    {
        $this->actingAs($this->user('finance'))->post('/finance/entries', ['direction' => 'out', 'category' => 'Veterinary', 'amount' => 10000, 'payment_status' => 'partial', 'paid_now' => 4000, 'occurred_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $e = LedgerEntry::sole();
        $this->assertSame(['5020' => [1000000, 0], '1000' => [0, 400000], '2000' => [0, 600000]], $this->lines($e));
        $this->assertSame(600000, Accounting::owedMinor($e));

        $this->actingAs($this->user('finance'))->post("/finance/entries/{$e->id}/paid", [])->assertSessionHasNoErrors();
        $this->assertSame(0, Accounting::owedMinor($e));
        $this->assertEquals(0, collect(Accounting::balanceSheet(now()->toDateString())['liabilities'])->sum('amount'));
        $this->assertSame(-10000.0, Accounting::balanceSheet(now()->toDateString())['totalAssets'], 'all of it left the cash box');
    }

    public function test_a_sale_with_a_fee_credits_gross_sales_and_debits_the_fee(): void
    {
        $this->actingAs($this->user('finance'))->post('/finance/sales', ['sold_on' => now()->toDateString(), 'category' => 'product', 'item' => 'Milk 40 L', 'gross' => 8000, 'fee' => 500])->assertSessionHasNoErrors();
        $this->assertSame(['1000' => [750000, 0], '5100' => [50000, 0], '4010' => [0, 800000]], $this->lines(LedgerEntry::sole()));
        $pl = Accounting::profitAndLoss(now()->startOfYear()->toDateString(), now()->toDateString());
        $this->assertSame([8000.0, 500.0, 7500.0], [$pl['totalIncome'], $pl['totalExpenses'], $pl['net']]);
    }

    public function test_reversing_an_entry_posts_the_mirror_image_and_leaves_both_on_record(): void
    {
        $this->actingAs($this->user('finance'))->post('/finance/entries', ['direction' => 'out', 'category' => 'Transport', 'amount' => 3000, 'payment_status' => 'pending', 'occurred_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $e = LedgerEntry::sole();
        $this->actingAs($this->user('finance'))->post("/finance/entries/{$e->id}/reverse")->assertRedirect();

        $this->assertSame(2, JournalEntry::count());
        $this->assertSame(0, Accounting::owedMinor($e) + Accounting::owedMinor(LedgerEntry::where('reverses_id', $e->id)->sole()));
        $this->assertTrue(Accounting::balances()->every(fn ($r) => $r['balance'] === 0), 'every account back to zero');
        $this->actingAs($this->user('finance'))->post("/finance/entries/{$e->id}/paid")->assertStatus(422);
    }

    public function test_a_manual_journal_must_balance_and_the_database_refuses_unbalanced_or_edited_journals(): void
    {
        $finance = $this->user('finance');
        $line = fn (string $key, $d, $c) => ['account_id' => $this->code($key), 'debit' => $d, 'credit' => $c];
        $this->actingAs($finance)->post('/finance/journals', ['posted_on' => now()->toDateString(), 'memo' => 'Capital', 'lines' => [$line('cash', 50000, null), $line('capital', null, 40000)]])->assertSessionHasErrors('lines');
        $this->actingAs($finance)->post('/finance/journals', ['posted_on' => now()->toDateString(), 'memo' => 'Owner put cash in', 'lines' => [$line('cash', 50000, null), $line('capital', null, 50000)]])->assertSessionHasNoErrors();
        $this->actingAs($finance)->post('/finance/journals', ['posted_on' => now()->toDateString(), 'memo' => 'Cash to bank', 'lines' => [$line('bank', 20000, null), $line('cash', null, 20000)]])->assertSessionHasNoErrors();

        $bs = Accounting::balanceSheet(now()->toDateString());
        $this->assertSame([50000.0, 50000.0, true], [$bs['totalAssets'], $bs['totalEquity'], $bs['balanced']]);
        $this->assertSame([1, 2], JournalEntry::orderBy('entry_no')->pluck('entry_no')->all());

        $j = JournalEntry::first();
        $this->expectExceptionMessage('append-only');
        DB::table('journal_lines')->where('journal_entry_id', $j->id)->update(['debit_minor' => 1]);
    }

    public function test_postgres_refuses_an_unbalanced_journal(): void
    {
        $j = JournalEntry::create(['entry_no' => 1, 'posted_on' => now(), 'memo' => 'x', 'source' => 'manual', 'recorded_by' => 'x']);
        JournalLine::create(['journal_entry_id' => $j->id, 'account_id' => $this->code('cash'), 'debit_minor' => 100]);
        $this->expectExceptionMessage('not balanced');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_another_farm_cannot_see_these_books(): void
    {
        $this->actingAs($this->user('finance'))->post('/finance/entries', ['direction' => 'in', 'category' => 'Other', 'amount' => 100, 'occurred_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(1, JournalEntry::count());

        Tenant::enter(Organization::create(['name' => 'Other Farm', 'slug' => 'other-farm'])->id);
        try {
            $this->assertSame(0, JournalEntry::count());
            $this->assertSame(0, JournalLine::count());
            $this->assertSame(0, Account::count());
        } finally {
            Tenant::leave();
        }
    }

    public function test_the_books_page_shows_the_statements_to_finance_only(): void
    {
        $this->actingAs($this->user('finance'))->post('/finance/entries', ['direction' => 'out', 'category' => 'Labor', 'amount' => 2000, 'occurred_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($this->user('ceo'))->get('/finance/books')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Books')
            ->where('trial.balanced', true)->where('trial.debit', 2000)->where('pl.net', -2000)->where('balance.balanced', true)->has('journal', 1));
        $this->actingAs($this->user('vet'))->get('/finance/books')->assertForbidden();
    }
}
