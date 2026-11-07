<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The workbook's "Birth Event ID": a farm-born kid points at the kidding it came from (D-042). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animals', function (Blueprint $t) {
            $t->foreignId('birth_kidding_id')->nullable()->constrained('kiddings');
        });
    }

    public function down(): void
    {
        Schema::table('animals', function (Blueprint $t) {
            $t->dropConstrainedForeignId('birth_kidding_id');
        });
    }
};
