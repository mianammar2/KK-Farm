<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-110: a goat with no teeth and no known birth date gets its age estimated from its first weighing. Each breed
 * has its own "light" line, since a light Makhi Cheeni weighs more than a light Teddy. Empty means no estimate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('breeds', fn (Blueprint $t) => $t->decimal('young_below_kg', 5, 1)->nullable());
    }

    public function down(): void
    {
        Schema::table('breeds', fn (Blueprint $t) => $t->dropColumn('young_below_kg'));
    }
};
