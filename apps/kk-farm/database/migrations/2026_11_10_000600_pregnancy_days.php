<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-098: pregnancy is counted in days after mating, not months. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_checkups', fn (Blueprint $t) => $t->unsignedSmallInteger('days_pregnant')->nullable());
        DB::table('health_checkups')->whereNotNull('months_pregnant')->update(['days_pregnant' => DB::raw('round(months_pregnant * 30)')]);
        Schema::table('health_checkups', fn (Blueprint $t) => $t->dropColumn('months_pregnant'));
        DB::table('field_definitions')->where('key', 'pregnancy_months')->where('builtin', true)->update([
            'key' => 'pregnancy_days', 'label' => 'Days pregnant (about)',
            'help' => 'Days since she was mated. Works out the expected kidding date (gestation about 150 days).',
        ]);
    }

    public function down(): void
    {
        Schema::table('health_checkups', fn (Blueprint $t) => $t->decimal('months_pregnant', 3, 1)->nullable());
        DB::table('health_checkups')->whereNotNull('days_pregnant')->update(['months_pregnant' => DB::raw('round(days_pregnant / 30.0, 1)')]);
        Schema::table('health_checkups', fn (Blueprint $t) => $t->dropColumn('days_pregnant'));
        DB::table('field_definitions')->where('key', 'pregnancy_days')->where('builtin', true)->update(['key' => 'pregnancy_months', 'label' => 'Months pregnant (about)', 'help' => 'Works out the expected kidding date (gestation about 5 months).']);
    }
};
