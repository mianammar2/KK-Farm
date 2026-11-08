<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-074: workbook "Medicine Stock" and "Medicine Movements".
 *
 * - Stock is never typed: it is the sum of append-only movements (opening, purchase, issue, disposal, return, adjustment).
 * - Receipts carry a batch/lot and expiry date; issues take the batch that expires first (FEFO) and never an expired one.
 * - A treatment that names a stock medicine and quantity issues it from the store, so it is entered once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicines', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('code', 20);
            $t->string('name', 120);
            $t->string('category', 30);
            $t->string('unit', 12); // ml | tablet | dose | vial | g | bolus | sachet
            $t->decimal('reorder_level', 12, 2)->default(0);
            $t->unsignedBigInteger('cost_per_unit_minor')->default(0);
            $t->string('storage', 80)->nullable();
            $t->string('supplier', 80)->nullable();
            $t->string('notes', 200)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['org_id', 'code']);
        });

        Schema::create('medicine_movements', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('medicine_id')->constrained();
            $t->date('moved_on');
            $t->string('type', 20); // opening | purchase | issue | disposal | return | adjustment | other
            $t->decimal('qty', 12, 2); // signed: + in, - out
            $t->string('batch', 40)->nullable();
            $t->date('expiry_on')->nullable(); // set on receipts
            $t->unsignedBigInteger('rate_minor')->nullable();
            $t->string('party', 80)->nullable();
            $t->string('reference', 60)->nullable();
            $t->foreignId('treatment_id')->nullable()->constrained();
            $t->foreignId('ledger_entry_id')->nullable()->constrained();
            $t->string('notes', 200)->nullable();
            $t->string('recorded_by');
            $t->timestamps();
            $t->index(['medicine_id', 'batch']);
        });

        Schema::table('treatments', function (Blueprint $t) {
            $t->foreignId('medicine_id')->nullable()->constrained();
            $t->decimal('qty_used', 12, 2)->nullable();
        });

        foreach (['medicines', 'medicine_movements'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        Schema::table('treatments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('medicine_id');
            $t->dropColumn('qty_used');
        });
        Schema::drop('medicine_movements');
        Schema::drop('medicines');
    }
};
