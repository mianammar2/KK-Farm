<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** D-172: days a purchased or transferred goat stays in intake quarantine before the vet's clearance (farm setting). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->unsignedSmallInteger('quarantine_days')->default(14));
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('quarantine_days'));
    }
};
