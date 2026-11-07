<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-047: age categories are worked out from the birth date with per-farm boundaries (KK Farms: kid under
 * 3 months, grower to 12 months, then adult). Stored age/category values are kept only when chosen by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->unsignedSmallInteger('grower_from_months')->default(3);
            $t->unsignedSmallInteger('adult_from_months')->default(12);
        });

        // Values that registration filled in automatically go stale as goats grow: clear them so they are
        // worked out live. (Until now they were only ever automatic unless chosen, and cannot be told apart,
        // so only the automatic kid categories, which are the ones that age out, are cleared.)
        DB::table('animals')->whereIn('animal_class', ['male_kid', 'female_kid'])->whereNotNull('birth_date')->update(['animal_class' => null]);
        DB::table('animals')->update(['age_category' => null]);
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['grower_from_months', 'adult_from_months']));
    }
};
