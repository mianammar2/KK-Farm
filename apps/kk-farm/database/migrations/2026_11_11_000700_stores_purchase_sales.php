<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-178: Inventory (a general store for consumables and spares beside the feed and medicine stores), Purchase
 * (suppliers and purchase orders whose receipt puts goods into the right store and records what is owed), and
 * Sales (customers, linked to sales).
 */
return new class extends Migration
{
    private const TABLES = ['store_items', 'store_moves', 'suppliers', 'purchase_orders', 'purchase_order_lines', 'customers'];

    public function up(): void
    {
        Schema::create('store_items', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('code', 20);
            $t->string('name', 80);
            $t->string('category', 20); // bedding | cleaning | spares | tools | packaging | other
            $t->string('unit', 12);
            $t->decimal('reorder_level', 10, 2)->default(0);
            $t->bigInteger('cost_per_unit_minor')->default(0);
            $t->boolean('active')->default(true);
            $t->string('notes', 200)->nullable();
            $t->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX store_items_code ON store_items (org_id, upper(code))');

        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('name', 100);
            $t->string('phone', 30)->nullable();
            $t->string('city', 60)->nullable();
            $t->string('supplies', 120)->nullable(); // what they sell: feed, medicine, goats…
            $t->string('notes', 300)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('po_no', 20);
            $t->foreignId('supplier_id')->constrained('suppliers');
            $t->date('ordered_on');
            $t->date('expected_on')->nullable();
            $t->string('status', 12)->default('draft'); // draft | ordered | received | cancelled
            $t->string('notes', 300)->nullable();
            $t->string('created_by');
            $t->date('received_on')->nullable();
            $t->string('received_by')->nullable();
            $t->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX purchase_orders_no ON purchase_orders (org_id, po_no)');

        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $t->string('kind', 10); // feed | medicine | store
            $t->unsignedBigInteger('item_id');
            $t->decimal('qty', 12, 2);
            $t->bigInteger('rate_minor');
            $t->string('batch', 40)->nullable();
            $t->date('expiry_on')->nullable();
            $t->timestamps();
        });

        Schema::create('store_moves', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('store_item_id')->constrained('store_items');
            $t->date('moved_on');
            $t->string('type', 12); // opening | purchase | receipt | issue | adjustment
            $t->decimal('qty', 12, 2); // + in, − out
            $t->bigInteger('rate_minor')->nullable();
            $t->string('party', 80)->nullable();
            $t->string('issued_to', 80)->nullable(); // pen, shed or person
            $t->string('reference', 60)->nullable();
            $t->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders');
            $t->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $t->string('recorded_by');
            $t->string('notes', 200)->nullable();
            $t->timestamps();
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('name', 100);
            $t->string('phone', 30)->nullable();
            $t->string('city', 60)->nullable();
            $t->string('type', 20)->default('individual'); // butcher | trader | farmer | individual | other
            $t->string('notes', 300)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::table('sales', fn (Blueprint $t) => $t->foreignId('customer_id')->nullable()->constrained('customers'));
        foreach (['feed_stock_movements', 'medicine_movements'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders'));
        }

        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        foreach (['feed_stock_movements', 'medicine_movements'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('purchase_order_id'));
        }
        Schema::table('sales', fn (Blueprint $t) => $t->dropConstrainedForeignId('customer_id'));
        foreach (['store_moves', 'customers', 'purchase_order_lines', 'purchase_orders', 'suppliers', 'store_items'] as $table) {
            Schema::drop($table);
        }
    }
};
