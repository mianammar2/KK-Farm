<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-113: Herd Value. The live-weight market rate (PKR per KG) the administrator enters, kept append-only so its
 * history can be charted; a correction is a new row for the same day. A batch's purchase rate per KG on each goat,
 * and a farm-wide feed price used until the Feed Store has priced feeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_rates', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->date('rate_on');
            $t->string('purpose', 30)->nullable(); // null = every goat; else Classification::PURPOSES key
            $t->unsignedBigInteger('rate_minor_per_kg');
            $t->string('note', 200)->nullable();
            $t->string('recorded_by');
            $t->timestamps();
            $t->index(['rate_on', 'purpose']);
        });
        DB::statement('ALTER TABLE market_rates ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE market_rates FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON market_rates USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE market_rates ADD CONSTRAINT market_rates_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');

        Schema::table('animals', fn (Blueprint $t) => $t->unsignedBigInteger('purchase_rate_minor_per_kg')->nullable());
        Schema::table('organizations', fn (Blueprint $t) => $t->unsignedBigInteger('feed_price_minor_per_kg')->nullable());
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('feed_price_minor_per_kg'));
        Schema::table('animals', fn (Blueprint $t) => $t->dropColumn('purchase_rate_minor_per_kg'));
        Schema::drop('market_rates');
    }
};
