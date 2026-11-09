<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** D-111: crude protein (CP) the feed should carry, as % of the feed, per age group. Empty = not set. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->decimal('kid_cp_pct', 4, 1)->nullable();
            $t->decimal('grower_cp_pct', 4, 1)->nullable();
            $t->decimal('adult_cp_pct', 4, 1)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['kid_cp_pct', 'grower_cp_pct', 'adult_cp_pct']));
    }
};
