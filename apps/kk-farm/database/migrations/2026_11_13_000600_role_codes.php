<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-239: the farm's own settings for each gender code (name, purpose, category, starting age); defaults until changed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_codes', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('code', 4);
            $t->string('label', 40);
            $t->string('purpose', 14)->nullable();
            $t->string('animal_class', 24);
            $t->string('stage_code', 12)->nullable();
            $t->timestamps();
            $t->unique(['org_id', 'code']);
        });
        DB::statement('ALTER TABLE role_codes ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE role_codes FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON role_codes USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE role_codes ADD CONSTRAINT role_codes_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
    }

    public function down(): void
    {
        Schema::drop('role_codes');
    }
};
