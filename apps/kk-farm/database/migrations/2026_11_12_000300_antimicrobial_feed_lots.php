<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-209: App 05 antimicrobial use report (the vet marks critically important antibiotics) and App 06 feed safety lots
 * (BR-016, UAT-17): feeds that need a safety test arrive as a quarantined lot that cannot be fed or mixed until a
 * second person releases it on moisture and aflatoxin results; a rejected lot is written off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', fn (Blueprint $t) => $t->boolean('critically_important')->default(false));
        Schema::table('feed_items', fn (Blueprint $t) => $t->boolean('safety_test')->default(false));
        Schema::table('organizations', function (Blueprint $t) {
            $t->decimal('feed_max_moisture_pct', 4, 1)->default(14);
            $t->decimal('feed_max_aflatoxin_ppb', 6, 1)->default(20);
        });
        Schema::create('feed_lots', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('lot_no', 20);
            $t->foreignId('feed_item_id')->constrained('feed_items');
            $t->foreignId('movement_id')->constrained('feed_stock_movements');
            $t->date('received_on');
            $t->decimal('qty_kg', 12, 2);
            $t->string('supplier', 80)->nullable();
            $t->string('status', 12); // quarantined | released | rejected
            $t->decimal('moisture_pct', 4, 1)->nullable();
            $t->decimal('aflatoxin_ppb', 6, 1)->nullable();
            $t->string('appearance', 200)->nullable(); // mould, smell, foreign matter
            $t->string('sample_ref', 60)->nullable();
            $t->string('tested_by')->nullable();
            $t->timestamp('tested_at')->nullable();
            $t->string('received_by');
            $t->string('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 300)->nullable();
            $t->foreignId('write_off_movement_id')->nullable()->constrained('feed_stock_movements');
            $t->timestamps();
            $t->unique(['org_id', 'lot_no']);
        });
        DB::statement('ALTER TABLE feed_lots ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE feed_lots FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON feed_lots USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE feed_lots ADD CONSTRAINT feed_lots_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
    }

    public function down(): void
    {
        Schema::drop('feed_lots');
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['feed_max_moisture_pct', 'feed_max_aflatoxin_ppb']));
        Schema::table('feed_items', fn (Blueprint $t) => $t->dropColumn('safety_test'));
        Schema::table('medicines', fn (Blueprint $t) => $t->dropColumn('critically_important'));
    }
};
