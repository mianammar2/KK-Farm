<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-338: a goat count by scanning (whole farm, a shed or a PEN): expected vs counted, kept as an append-only record. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goat_counts', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('scope', 10); // farm | shed | pen
            $t->foreignId('shed_id')->nullable()->constrained('sheds')->nullOnDelete();
            $t->foreignId('pen_id')->nullable()->constrained('pens')->nullOnDelete();
            $t->string('scope_label', 120);
            $t->unsignedInteger('expected');
            $t->unsignedInteger('counted');
            $t->jsonb('by_breed');
            $t->jsonb('missing');
            $t->jsonb('extra');
            $t->jsonb('unknown');
            $t->string('notes', 200)->nullable();
            $t->string('recorded_by', 80);
            $t->timestamps();
        });
        DB::statement('ALTER TABLE goat_counts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE goat_counts FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON goat_counts USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE goat_counts ADD CONSTRAINT goat_counts_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
    }

    public function down(): void
    {
        Schema::drop('goat_counts');
    }
};
