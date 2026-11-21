<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** D-371: the farm's vets say CC for a medicine's millilitres (1 CC = 1 ml): the unit is shown and kept as CC. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('medicines')->where('unit', 'ml')->update(['unit' => 'CC']);
    }

    public function down(): void
    {
        DB::table('medicines')->where('unit', 'CC')->update(['unit' => 'ml']);
    }
};
