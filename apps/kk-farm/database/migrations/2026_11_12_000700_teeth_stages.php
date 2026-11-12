<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-220: teeth stages per farm (Settings → Age Groups). Each stage has a name, the age range it means, and the age used
 * to estimate a bought goat's birth date. A goat stores the stage key, so a stage is renamed freely but its key stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teeth_stages', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('key', 20);
            $t->string('label', 60);
            $t->unsignedSmallInteger('from_months')->default(0);
            $t->unsignedSmallInteger('to_months')->nullable(); // null = and older
            $t->unsignedSmallInteger('estimate_months');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->unique(['org_id', 'key']);
        });
        DB::statement('ALTER TABLE teeth_stages ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE teeth_stages FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON teeth_stages USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE teeth_stages ADD CONSTRAINT teeth_stages_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
    }

    public function down(): void
    {
        Schema::drop('teeth_stages');
    }
};
