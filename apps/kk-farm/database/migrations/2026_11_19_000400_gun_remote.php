<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-354: the KK Farm gun app reports to the server by itself (no adb): each installed app is one gun, known by a random
 * install id (never a serial, IMEI or MAC). It sends its version, reader state and, when asked, its own log; the
 * Administrator sends it test commands from Settings → Guns. Diagnostics, not farm history: the latest state and log
 * replace the previous ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gun_devices', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->uuid('install_id');
            $t->string('label', 60);
            $t->string('app_version', 20)->nullable();
            $t->unsignedInteger('app_code')->nullable();
            $t->string('reader', 20)->nullable(); // none | ready | error
            $t->jsonb('state')->nullable();
            $t->text('logs')->nullable();
            $t->timestamp('logs_at')->nullable();
            $t->string('last_user', 80)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
            $t->unique(['org_id', 'install_id']);
        });
        Schema::create('gun_commands', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('gun_device_id')->constrained('gun_devices')->cascadeOnDelete();
            $t->string('command', 30);
            $t->string('status', 10)->default('pending'); // pending | sent | done | failed
            $t->text('result')->nullable();
            $t->string('requested_by', 80);
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('done_at')->nullable();
            $t->timestamps();
        });
        foreach (['gun_devices', 'gun_commands'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        Schema::drop('gun_commands');
        Schema::drop('gun_devices');
    }
};
