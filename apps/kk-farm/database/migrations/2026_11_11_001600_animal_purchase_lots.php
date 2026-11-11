<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-190: V3 cross-app journey A (flow 24) Purchase-to-Quarantine: a goat purchase request, approved by someone else,
 * becomes a market / breeder lot APL-YYYY-NNNN. It is received, counted and each goat inspected (origin ID, clinical
 * screening); discrepancies are flagged and reconciled; accepted goats get Animal IDs and EPC tags and go to a dedicated
 * quarantine pen (intake quarantine, D-172); the cost and supplier bill post once and every goat keeps its lot and cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('animal_purchase_lots', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('lot_no', 20);
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $t->string('source', 120);                 // market or breeder
            $t->string('purpose', 160)->nullable();
            $t->unsignedInteger('expected_head');
            $t->bigInteger('budget_minor');            // agreed / approved price for the lot
            $t->string('status', 12)->default('requested'); // requested | approved | rejected | received | cancelled
            $t->string('requested_by');
            $t->string('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->string('decision_note', 300)->nullable();
            $t->date('received_on')->nullable();
            $t->string('received_by')->nullable();
            $t->foreignId('quarantine_pen_id')->nullable()->constrained('pens');
            $t->bigInteger('paid_minor')->nullable();      // final price to the supplier after rejections
            $t->bigInteger('transport_minor')->nullable();
            $t->string('discrepancy', 1000)->nullable();
            $t->string('reconciled_by')->nullable();
            $t->string('reconciliation', 1000)->nullable();
            $t->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $t->foreignId('transport_ledger_entry_id')->nullable()->constrained('ledger_entries');
            $t->timestamps();
            $t->unique(['org_id', 'lot_no']);
        });
        Schema::create('animal_purchase_items', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('lot_id')->constrained('animal_purchase_lots');
            $t->string('origin_ref', 40)->nullable(); // the seller's / market tag or ID
            $t->string('sex', 6);
            $t->foreignId('breed_id')->constrained('breeds');
            $t->decimal('weight_kg', 6, 2)->nullable();
            $t->string('screening', 10);              // ok | sick | rejected
            $t->string('notes', 300)->nullable();
            $t->foreignUuid('animal_id')->nullable()->constrained('animals');
            $t->bigInteger('cost_minor')->nullable();
            $t->timestamps();
        });
        Schema::table('animals', fn (Blueprint $t) => $t->foreignId('purchase_lot_id')->nullable()->constrained('animal_purchase_lots'));
        foreach (['animal_purchase_lots', 'animal_purchase_items'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        Schema::table('animals', fn (Blueprint $t) => $t->dropConstrainedForeignId('purchase_lot_id'));
        Schema::drop('animal_purchase_items');
        Schema::drop('animal_purchase_lots');
    }
};
