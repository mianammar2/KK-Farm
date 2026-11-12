<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** D-240: farm-made gender codes (their own gender) and switching a code off (hidden from the Gender field). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_codes', function (Blueprint $t) {
            $t->string('sex', 6)->nullable();
            $t->boolean('active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('role_codes', fn (Blueprint $t) => $t->dropColumn(['sex', 'active']));
    }
};
