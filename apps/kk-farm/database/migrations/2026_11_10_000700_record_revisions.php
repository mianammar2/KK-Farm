<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-099: corrections. A person fixes what they recorded (the administrator anyone's); each correction is one
 * append-only revision row: which record, which fields from what to what, who, when and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_revisions', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('record_type', 40); // checkup | weight
            $t->unsignedBigInteger('record_id');
            $t->foreignUuid('animal_id')->nullable()->constrained('animals');
            $t->jsonb('changes'); // {field: [old, new]}
            $t->string('reason', 300)->nullable();
            $t->string('changed_by');
            $t->string('changed_role', 40);
            $t->timestamp('created_at')->nullable();
            $t->index(['record_type', 'record_id']);
        });
        DB::statement('ALTER TABLE record_revisions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE record_revisions FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON record_revisions USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE record_revisions ADD CONSTRAINT record_revisions_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
        // D-099: what happens after a checkup: a recheck date (a task for the vets).
        Schema::table('health_checkups', function (Blueprint $t) {
            $t->date('follow_up_on')->nullable();
            $t->string('follow_up_note', 200)->nullable();
            $t->foreignId('follow_up_task_id')->nullable()->constrained('tasks');
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION revisions_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Corrections are kept: a revision cannot be changed or deleted.';
            END $$;
            CREATE TRIGGER record_revisions_append_only BEFORE UPDATE OR DELETE ON record_revisions FOR EACH ROW EXECUTE FUNCTION revisions_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS record_revisions_append_only ON record_revisions;');
        Schema::table('health_checkups', function (Blueprint $t) {
            $t->dropConstrainedForeignId('follow_up_task_id');
            $t->dropColumn(['follow_up_on', 'follow_up_note']);
        });
        Schema::drop('record_revisions');
        DB::unprepared('DROP FUNCTION IF EXISTS revisions_append_only();');
    }
};
