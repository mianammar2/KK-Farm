<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-192: V3 cross-app journey C Feed-to-True-Cost (flow 26): physical stock counts (book vs counted, shrinkage posted as
 * an adjustment), the weighed output of a TMR batch (mixing shrinkage) and the farm's feed-conversion alert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_stock_counts', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('feed_item_id')->constrained('feed_items');
            $t->date('counted_on');
            $t->decimal('book_kg', 12, 2);
            $t->decimal('counted_kg', 12, 2);
            $t->decimal('diff_kg', 12, 2);               // counted − book (negative = shrinkage)
            $t->bigInteger('value_minor');               // diff × cost per KG
            $t->foreignId('movement_id')->nullable()->constrained('feed_stock_movements');
            $t->string('notes', 200)->nullable();
            $t->string('counted_by');
            $t->timestamps();
        });
        DB::statement('ALTER TABLE feed_stock_counts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE feed_stock_counts FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON feed_stock_counts USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE feed_stock_counts ADD CONSTRAINT feed_stock_counts_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
        Schema::table('tmr_batches', fn (Blueprint $t) => $t->decimal('output_kg', 10, 2)->nullable());
        Schema::table('organizations', fn (Blueprint $t) => $t->decimal('fcr_alert', 4, 1)->default(10));
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('fcr_alert'));
        Schema::table('tmr_batches', fn (Blueprint $t) => $t->dropColumn('output_kg'));
        Schema::drop('feed_stock_counts');
    }
};
