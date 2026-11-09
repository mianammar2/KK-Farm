<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** D-112: the crude protein printed on a feed's bag label (e.g. "CP 32" = 32%). Empty = not known. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_items', fn (Blueprint $t) => $t->decimal('cp_pct', 4, 1)->nullable());
    }

    public function down(): void
    {
        Schema::table('feed_items', fn (Blueprint $t) => $t->dropColumn('cp_pct'));
    }
};
