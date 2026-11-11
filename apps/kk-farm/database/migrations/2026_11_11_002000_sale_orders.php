<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-194: V3 cross-app journey E Sale-to-Cash & Exit (flow 28): sale orders (quote → approved terms → reserved → loaded by
 * RFID scan → dispatched with invoice → collected), their goats with price and cost basis, every loading scan (dispatch
 * errors), and customer receivables on income entries.
 */
return new class extends Migration
{
    private const TABLES = ['sale_orders', 'sale_order_lines', 'sale_scans'];

    public function up(): void
    {
        Schema::create('sale_orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('order_no', 20);
            $t->foreignId('customer_id')->nullable()->constrained('customers');
            $t->string('buyer', 120)->nullable();
            $t->string('basis', 8);                  // per_head | per_kg
            $t->string('terms', 8)->default('cash');  // cash | credit
            $t->date('due_on')->nullable();
            $t->string('status', 12)->default('quote'); // quote | reserved | dispatched | cancelled
            $t->string('created_by');
            $t->string('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->jsonb('checklist')->nullable();      // dispatch checklist ticks
            $t->date('dispatched_on')->nullable();
            $t->string('dispatched_by')->nullable();
            $t->string('invoice_no', 20)->nullable();
            $t->timestamp('collected_at')->nullable();
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['org_id', 'order_no']);
        });
        Schema::create('sale_order_lines', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('order_id')->constrained('sale_orders');
            $t->foreignUuid('animal_id')->constrained('animals');
            $t->decimal('weight_kg', 6, 2)->nullable();
            $t->bigInteger('price_minor');
            $t->bigInteger('market_minor')->nullable(); // market value at quote, for the approver
            $t->string('status', 10)->default('quoted'); // quoted | reserved | loaded | sold | removed
            $t->string('removed_reason', 200)->nullable();
            $t->timestamp('loaded_at')->nullable();
            $t->string('scanned_code', 80)->nullable();
            $t->foreignId('sale_id')->nullable()->constrained('sales');
            $t->jsonb('cost')->nullable();             // cost basis at dispatch: purchase, feed, health (minor)
            $t->timestamps();
        });
        Schema::create('sale_scans', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('order_id')->constrained('sale_orders');
            $t->string('code', 80);
            $t->string('result', 12); // match | duplicate | not_on_order | unknown
            $t->string('scanned_by');
            $t->timestamps();
        });
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::drop($table);
        }
    }
};
