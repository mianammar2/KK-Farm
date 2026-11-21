<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-374: the store counts medicines in ml again (as bought: a 100 ml bottle); only giving a dose is written in CC
 * (1 CC = 1 ml, so 1 CC given takes 1 ml from the store). Undoes D-371's unit change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('medicines')->where('unit', 'CC')->update(['unit' => 'ml']);
    }

    public function down(): void
    {
        DB::table('medicines')->where('unit', 'ml')->update(['unit' => 'CC']);
    }
};
