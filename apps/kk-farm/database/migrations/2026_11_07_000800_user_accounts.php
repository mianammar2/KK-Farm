<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** D-044: real accounts. The owner creates staff accounts; a staff member sets their own password on first sign-in. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('active')->default(true);
            $t->boolean('must_change_password')->default(false);
            $t->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['active', 'must_change_password', 'last_login_at']));
    }
};
