<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-243: page-level access the administrator can change. Defaults stay in config/kkfarm_pages.php; a row here
 * overrides one page for one role (role_page_access) or one person (user_page_access). No rows = D-241 behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_page_access', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('role', 20);
            $t->string('page_key', 80);
            $t->boolean('allowed');
            $t->timestamps();
            $t->unique(['org_id', 'role', 'page_key']);
        });
        Schema::create('user_page_access', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('page_key', 80);
            $t->boolean('allowed');
            $t->timestamps();
            $t->unique(['org_id', 'user_id', 'page_key']);
        });
        foreach (['role_page_access', 'user_page_access'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_page_access');
        Schema::dropIfExists('role_page_access');
    }
};
