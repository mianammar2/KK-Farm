<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-069: daily intake is a range per age: growers 3–4% of body weight, adults 2–3%; kids drink milk (no range).
 * Feed is planned at the middle of the range; each feeding keeps the range so "off plan" means outside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->decimal('kid_intake_min_pct', 4, 2)->nullable();
            $t->decimal('kid_intake_max_pct', 4, 2)->nullable();
            $t->decimal('grower_intake_min_pct', 4, 2)->default(3);
            $t->decimal('grower_intake_max_pct', 4, 2)->default(4);
            $t->decimal('adult_intake_min_pct', 4, 2)->default(2);
            $t->decimal('adult_intake_max_pct', 4, 2)->default(3);
        });
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['kid_intake_pct', 'grower_intake_pct', 'adult_intake_pct']));
        Schema::table('feed_usages', function (Blueprint $t) {
            $t->decimal('planned_min_kg', 10, 2)->nullable();
            $t->decimal('planned_max_kg', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('feed_usages', fn (Blueprint $t) => $t->dropColumn(['planned_min_kg', 'planned_max_kg']));
        Schema::table('organizations', function (Blueprint $t) {
            $t->decimal('kid_intake_pct', 4, 2)->nullable();
            $t->decimal('grower_intake_pct', 4, 2)->default(2);
            $t->decimal('adult_intake_pct', 4, 2)->default(3);
        });
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['kid_intake_min_pct', 'kid_intake_max_pct', 'grower_intake_min_pct', 'grower_intake_max_pct', 'adult_intake_min_pct', 'adult_intake_max_pct']));
    }
};
