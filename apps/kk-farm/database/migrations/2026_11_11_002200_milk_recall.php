<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-196: journey G Milk Quality-Hold-to-Recall. Storage temperature log per lot (append-only; an excursion holds the
 * lot), refund / credit and returned litres on milk sales of a recalled lot, and how far back a flagged doe's milk is
 * traced (days, default 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_lot_temps', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('lot_id')->constrained('milk_lots');
            $t->decimal('temp_c', 4, 1);
            $t->boolean('excursion')->default(false);
            $t->string('recorded_by');
            $t->timestamps();
            $t->index(['lot_id', 'created_at']);
        });
        DB::statement('ALTER TABLE milk_lot_temps ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE milk_lot_temps FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON milk_lot_temps USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE milk_lot_temps ADD CONSTRAINT milk_lot_temps_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');

        Schema::table('milk_dispatches', function (Blueprint $t) {
            $t->decimal('returned_l', 8, 2)->nullable();
            $t->string('adjustment', 10)->nullable(); // refund | credit
            $t->bigInteger('adjustment_minor')->nullable();
            $t->timestamp('adjusted_at')->nullable();
            $t->string('adjusted_by')->nullable();
        });
        Schema::table('organizations', fn (Blueprint $t) => $t->unsignedSmallInteger('milk_trace_days')->nullable());
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('milk_trace_days'));
        Schema::table('milk_dispatches', fn (Blueprint $t) => $t->dropColumn(['returned_l', 'adjustment', 'adjustment_minor', 'adjusted_at', 'adjusted_by']));
        Schema::drop('milk_lot_temps');
    }
};
