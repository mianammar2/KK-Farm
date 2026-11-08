<?php

use App\Support\Accounting;
use App\Support\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-083: double-entry bookkeeping.
 *
 * - A chart of accounts per farm (asset, liability, equity, income, expense), seeded with a standard farm chart.
 * - Every money event (expense, sale, feed or medicine purchase, payment, reversal, manual journal) is one journal
 *   entry whose lines have equal debits and credits. Postgres refuses an unbalanced entry and any edit or delete:
 *   a correction is a reversing entry.
 * - The ledger entries the farm already has are posted as journals, oldest first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('code', 10);
            $t->string('name', 80);
            $t->string('type', 10); // asset | liability | equity | income | expense
            $t->string('system_key', 30)->nullable(); // cash, bank, payable, receivable, capital, drawings, retained, fees, other_income, other_expense
            $t->string('category', 40)->nullable(); // the older ledger category this account takes (e.g. "Feed Purchase")
            $t->boolean('is_money')->default(false); // cash or bank: money can be paid from or into it
            $t->boolean('active')->default(true);
            $t->string('notes', 200)->nullable();
            $t->timestamps();
            $t->unique(['org_id', 'code']);
            $t->unique(['org_id', 'system_key']);
        });

        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->unsignedInteger('entry_no');
            $t->date('posted_on');
            $t->string('memo', 200);
            $t->string('source', 20); // expense | income | sale | payment | reversal | manual | opening
            $t->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $t->foreignId('reverses_id')->nullable()->constrained('journal_entries');
            $t->string('reference', 60)->nullable();
            $t->string('recorded_by');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['org_id', 'entry_no']);
            $t->index('posted_on');
        });

        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('journal_entry_id')->constrained('journal_entries');
            $t->foreignId('account_id')->constrained('accounts');
            $t->unsignedBigInteger('debit_minor')->default(0);
            $t->unsignedBigInteger('credit_minor')->default(0);
            $t->foreignUuid('animal_id')->nullable()->constrained('animals');
            $t->string('counterparty', 80)->nullable();
            $t->index(['account_id', 'journal_entry_id']);
        });
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side CHECK ((debit_minor > 0 AND credit_minor = 0) OR (credit_minor > 0 AND debit_minor = 0))');

        Schema::table('ledger_entries', function (Blueprint $t) {
            $t->foreignId('account_id')->nullable()->constrained('accounts'); // income or expense account
            $t->foreignId('money_account_id')->nullable()->constrained('accounts'); // paid from / received into (cash, bank)
            $t->decimal('fee', 14, 2)->nullable(); // sale fee or discount, already taken off the amount
            $t->decimal('paid_now', 14, 2)->nullable(); // part paid when the expense was recorded as partly paid
        });

        foreach (['accounts', 'journal_entries', 'journal_lines'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Journals are append-only: post a reversing entry instead of changing or deleting one.';
            END $$;
            CREATE TRIGGER journal_entries_append_only BEFORE UPDATE OR DELETE ON journal_entries FOR EACH ROW EXECUTE FUNCTION journal_append_only();
            CREATE TRIGGER journal_lines_append_only BEFORE UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION journal_append_only();

            CREATE OR REPLACE FUNCTION journal_balanced() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE d bigint; c bigint;
            BEGIN
                SELECT coalesce(sum(debit_minor), 0), coalesce(sum(credit_minor), 0) INTO d, c FROM journal_lines WHERE journal_entry_id = NEW.journal_entry_id;
                IF d <> c THEN
                    RAISE EXCEPTION 'Journal entry % is not balanced: debits % and credits %', NEW.journal_entry_id, d, c;
                END IF;
                RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER journal_lines_balanced AFTER INSERT ON journal_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION journal_balanced();
        SQL);

        // Post what each farm already has, inside that farm's tenant so RLS fills and checks org_id.
        foreach (DB::table('organizations')->pluck('id') as $org) {
            Tenant::enter($org);
            try {
                Accounting::seed();
                \App\Models\LedgerEntry::orderBy('id')->each(fn ($e) => Accounting::postLedger($e, 'Opening import'));
            } finally {
                Tenant::leave();
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_balanced ON journal_lines; DROP TRIGGER IF EXISTS journal_lines_append_only ON journal_lines; DROP TRIGGER IF EXISTS journal_entries_append_only ON journal_entries;');
        Schema::table('ledger_entries', function (Blueprint $t) {
            $t->dropConstrainedForeignId('account_id');
            $t->dropConstrainedForeignId('money_account_id');
            $t->dropColumn(['fee', 'paid_now']);
        });
        Schema::drop('journal_lines');
        Schema::drop('journal_entries');
        Schema::drop('accounts');
        DB::unprepared('DROP FUNCTION IF EXISTS journal_balanced(); DROP FUNCTION IF EXISTS journal_append_only();');
    }
};
