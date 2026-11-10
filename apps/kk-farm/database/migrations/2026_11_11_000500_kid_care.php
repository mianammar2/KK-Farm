<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-176: newborn kid care (Maternity → Kid Care): colostrum, bottle feeds, nursing checks, warming, fostering. Append-only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kid_care_records', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignUuid('kid_id')->constrained('animals');
            $t->string('kind', 20);                 // colostrum | bottle_feed | nursing_check | warming | fostered
            $t->dateTime('given_at');
            $t->unsignedSmallInteger('amount_ml')->nullable();
            $t->string('source', 20)->nullable();   // dam | other_doe | stored | replacer
            $t->string('suckling', 10)->nullable(); // good | weak | none (nursing check)
            $t->foreignUuid('foster_dam_id')->nullable()->constrained('animals');
            $t->string('recorded_by');
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->index(['kid_id', 'given_at']);
        });
        DB::statement('ALTER TABLE kid_care_records ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE kid_care_records FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON kid_care_records USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE kid_care_records ADD CONSTRAINT kid_care_records_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
    }

    public function down(): void
    {
        Schema::drop('kid_care_records');
    }
};
