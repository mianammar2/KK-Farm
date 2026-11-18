<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** D-344: the time a checkup, weighing or height was actually taken (optional; not when it was typed in). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_checkups', fn (Blueprint $t) => $t->time('checked_time')->nullable()->after('checked_on'));
        Schema::table('weight_records', fn (Blueprint $t) => $t->time('measured_time')->nullable()->after('measured_on'));
        Schema::table('height_records', fn (Blueprint $t) => $t->time('measured_time')->nullable()->after('measured_on'));
    }

    public function down(): void
    {
        Schema::table('health_checkups', fn (Blueprint $t) => $t->dropColumn('checked_time'));
        Schema::table('weight_records', fn (Blueprint $t) => $t->dropColumn('measured_time'));
        Schema::table('height_records', fn (Blueprint $t) => $t->dropColumn('measured_time'));
    }
};
