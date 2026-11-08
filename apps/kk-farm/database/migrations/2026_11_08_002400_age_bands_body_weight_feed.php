<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-067: KK Farms age bands (kid under 2 months, grower 2–14, adult from 14) and feeding by body weight:
 * a ration can give a % of the goat's latest weight per day (grower 2%, adult 3% by default), split over its feeds
 * by share, instead of fixed kg per goat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->unsignedSmallInteger('grower_from_months')->default(2)->change();
            $t->unsignedSmallInteger('adult_from_months')->default(14)->change();
            $t->decimal('kid_intake_pct', 4, 2)->nullable();
            $t->decimal('grower_intake_pct', 4, 2)->default(2);
            $t->decimal('adult_intake_pct', 4, 2)->default(3);
        });
        // Farms still on the old defaults move to the new ones; a farm that chose its own bands keeps them.
        DB::table('organizations')->where('grower_from_months', 3)->where('adult_from_months', 12)->update(['grower_from_months' => 2, 'adult_from_months' => 14]);

        Schema::table('ration_plans', fn (Blueprint $t) => $t->string('basis', 12)->default('per_head')); // per_head | body_weight
        Schema::table('ration_plan_lines', function (Blueprint $t) {
            $t->decimal('kg_per_head', 7, 3)->nullable()->change();
            $t->decimal('share_pct', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        DB::table('ration_plan_lines')->whereNull('kg_per_head')->update(['kg_per_head' => 0]);
        Schema::table('ration_plan_lines', function (Blueprint $t) {
            $t->dropColumn('share_pct');
            $t->decimal('kg_per_head', 7, 3)->nullable(false)->change();
        });
        Schema::table('ration_plans', fn (Blueprint $t) => $t->dropColumn('basis'));
        DB::table('organizations')->where('grower_from_months', 2)->where('adult_from_months', 14)->update(['grower_from_months' => 3, 'adult_from_months' => 12]);
        Schema::table('organizations', function (Blueprint $t) {
            $t->dropColumn(['kid_intake_pct', 'grower_intake_pct', 'adult_intake_pct']);
            $t->unsignedSmallInteger('grower_from_months')->default(3)->change();
            $t->unsignedSmallInteger('adult_from_months')->default(12)->change();
        });
    }
};
