<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** D-065: the register asks height in inches (stored in cm on the goat and weighing). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('field_definitions')->where('builtin', true)->where('key', 'height_cm')->update(['key' => 'height_in']);
        DB::table('field_definitions')->where('key', 'height_in')->where('label', 'Height cm')->update(['label' => 'Height (inches)']);
    }

    public function down(): void
    {
        DB::table('field_definitions')->where('key', 'height_in')->where('label', 'Height (inches)')->update(['label' => 'Height cm']);
        DB::table('field_definitions')->where('builtin', true)->where('key', 'height_in')->update(['key' => 'height_cm']);
    }
};
