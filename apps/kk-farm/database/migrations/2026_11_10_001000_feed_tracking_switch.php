<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-107: a farm can work out feed needs without recording feeding yet. With feed_tracking off there are no meal,
 * missed-feeding, store or shortage alerts: only how much feed the goats need. Farms already on the system start off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->boolean('feed_tracking')->default(true));
        DB::table('organizations')->update(['feed_tracking' => false]);
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('feed_tracking'));
    }
};
