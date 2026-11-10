<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-175: heat (oestrus) observations for does (Breeding → Heat Detection). Append-only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heat_records', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignUuid('dam_id')->constrained('animals');
            $t->date('observed_on');
            $t->string('intensity', 10);          // weak | moderate | strong
            $t->string('signs', 300)->nullable(); // tail flagging, bleating, mounting, swollen vulva, mucus …
            $t->string('observed_by');
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->index(['dam_id', 'observed_on']);
        });
        DB::statement('ALTER TABLE heat_records ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE heat_records FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON heat_records USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE heat_records ADD CONSTRAINT heat_records_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
    }

    public function down(): void
    {
        Schema::drop('heat_records');
    }
};
