<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-215: Odoo-style access rights. The farm business sets what each role opens and may do (organizations.role_modules,
 * role_gates; null keeps the built-in defaults); each user can then be given or refused an app or an action on top of
 * their role (users.module_access, gate_access: {key: true|false}; a key that is absent follows the role).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->jsonb('role_modules')->nullable();
            $t->jsonb('role_gates')->nullable();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->jsonb('module_access')->nullable();
            $t->jsonb('gate_access')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['module_access', 'gate_access']));
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['role_modules', 'role_gates']));
    }
};
