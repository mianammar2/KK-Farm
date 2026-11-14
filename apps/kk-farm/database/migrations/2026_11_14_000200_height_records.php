<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-262: a goat's height measured on its own (the vet's or the administrator's), for the height chart. Append-only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('height_records', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->uuid('animal_id');
            $t->date('measured_on');
            $t->decimal('height_cm', 6, 1);
            $t->string('measured_by', 80);
            $t->string('source', 12)->default('manual');
            $t->timestamps();
            $t->index(['animal_id', 'measured_on']);
        });
        DB::statement('ALTER TABLE height_records ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE height_records FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON height_records USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE height_records ADD CONSTRAINT height_records_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
        DB::statement('ALTER TABLE height_records ADD CONSTRAINT height_records_animal_fk FOREIGN KEY (animal_id) REFERENCES animals(id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('height_records');
    }
};
