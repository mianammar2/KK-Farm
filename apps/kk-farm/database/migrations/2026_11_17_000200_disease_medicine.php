<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-333: the medicines a veterinarian sets for a disease, offered first when a case of it is treated (suggestions only). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disease_medicine', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('disease_id')->constrained('diseases')->cascadeOnDelete();
            $t->foreignId('medicine_id')->constrained('medicines')->cascadeOnDelete();
            $t->unique(['disease_id', 'medicine_id']);
        });
        DB::statement('ALTER TABLE disease_medicine ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE disease_medicine FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON disease_medicine USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE disease_medicine ADD CONSTRAINT disease_medicine_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
    }

    public function down(): void
    {
        Schema::drop('disease_medicine');
    }
};
