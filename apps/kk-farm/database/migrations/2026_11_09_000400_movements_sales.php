<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-076: workbook "Animal Movements" and "Sales".
 *
 * - Every pen transfer and every exit (sale, death, culling, Qurbani, gift, transfer out) is one append-only
 *   movement row; the goat's pen and lifecycle status follow from it, never edited by hand.
 * - A sale is a sales row (gross, fee/discount, net, buyer, receipt) and an income ledger entry, entered once.
 *   Product sales (milk, manure) are sales without a goat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('animal_movements', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignUuid('animal_id')->constrained('animals');
            $t->date('moved_on');
            $t->string('type', 20); // arrival | transfer | sale | death | culling | sacrificed | gifted | transferred_out | other
            $t->foreignId('from_pen_id')->nullable()->constrained('pens');
            $t->foreignId('to_pen_id')->nullable()->constrained('pens');
            $t->string('destination', 120)->nullable(); // buyer, farm or place for exits
            $t->string('reason', 200)->nullable();
            $t->string('authorized_by', 80)->nullable();
            $t->unsignedBigInteger('amount_minor')->nullable();
            $t->string('evidence_ref', 80)->nullable();
            $t->string('notes', 500)->nullable();
            $t->string('recorded_by');
            $t->timestamps();
            $t->index(['animal_id', 'moved_on']);
            $t->index(['type', 'moved_on']);
        });

        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->date('sold_on');
            $t->foreignUuid('animal_id')->nullable()->constrained('animals');
            $t->string('category', 20); // live_goat | breeding_goat | product | other
            $t->string('item', 120)->nullable(); // for products: e.g. "Milk 40 L"
            $t->string('buyer', 120)->nullable();
            $t->unsignedBigInteger('gross_minor');
            $t->unsignedBigInteger('fee_minor')->default(0);
            $t->string('receipt_ref', 60)->nullable();
            $t->string('notes', 500)->nullable();
            $t->foreignId('movement_id')->nullable()->constrained('animal_movements');
            $t->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $t->string('recorded_by');
            $t->timestamps();
            $t->index('sold_on');
        });

        foreach (['animal_movements', 'sales'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        Schema::drop('sales');
        Schema::drop('animal_movements');
    }
};
