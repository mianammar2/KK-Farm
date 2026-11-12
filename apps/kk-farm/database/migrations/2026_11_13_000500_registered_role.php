<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** D-235: the role code picked in the registry's Gender field (BB, BD, PD …); it fills gender, purpose and category. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animals', fn (Blueprint $t) => $t->string('registered_role', 4)->nullable());
    }

    public function down(): void
    {
        Schema::table('animals', fn (Blueprint $t) => $t->dropColumn('registered_role'));
    }
};
