<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-177: TMR formulas (a recipe in % of feeds; the formula is also a feed item of category TMR, so it can be rationed
 * and stocked), TMR mixing batches (ingredients leave stock, the mixed TMR enters it at its real cost), and wasted
 * feed next to refused feed on each feeding record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_usages', fn (Blueprint $t) => $t->decimal('wasted_kg', 10, 2)->nullable());

        Schema::create('tmr_formulas', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('feed_item_id')->constrained('feed_items'); // the TMR as a feed
            $t->string('name', 80);
            $t->string('notes', 500)->nullable();
            $t->boolean('active')->default(true);
            $t->string('created_by');
            $t->timestamps();
        });
        Schema::create('tmr_formula_lines', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('tmr_formula_id')->constrained('tmr_formulas')->cascadeOnDelete();
            $t->foreignId('feed_item_id')->constrained('feed_items');
            $t->decimal('pct', 5, 2);
            $t->timestamps();
        });
        Schema::create('tmr_batches', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('tmr_formula_id')->constrained('tmr_formulas');
            $t->string('batch_no', 30);
            $t->date('mixed_on');
            $t->decimal('total_kg', 10, 2);
            $t->bigInteger('cost_minor')->default(0); // total cost of the ingredients used
            $t->string('mixed_by');
            $t->string('notes', 300)->nullable();
            $t->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX tmr_batches_no ON tmr_batches (org_id, batch_no)');
        Schema::table('feed_stock_movements', fn (Blueprint $t) => $t->foreignId('tmr_batch_id')->nullable()->constrained('tmr_batches'));

        foreach (['tmr_formulas', 'tmr_formula_lines', 'tmr_batches'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        Schema::table('feed_stock_movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('tmr_batch_id'));
        Schema::drop('tmr_batches');
        Schema::drop('tmr_formula_lines');
        Schema::drop('tmr_formulas');
        Schema::table('feed_usages', fn (Blueprint $t) => $t->dropColumn('wasted_kg'));
    }
};
