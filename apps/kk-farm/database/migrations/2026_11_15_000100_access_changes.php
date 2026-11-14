<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-266: the history of every access-rights change (who, when, which role or person, which app, action or page, old → new)
 * so any save can be undone. Append-only: a rollback adds new rows; nothing is edited or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_changes', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->uuid('batch');                 // one save = one batch
            $t->string('actor', 120);
            $t->string('kind', 16);            // role_module | role_gate | role_page | user_module | user_gate | user_page
            $t->string('subject', 40);         // a role key, or a user id
            $t->string('subject_label', 120);
            $t->string('key', 80);             // the app, action or page key
            $t->string('item_label', 160);
            $t->string('old_value', 1)->nullable(); // '1' allowed, '0' refused, null = follows the role (person ticks only)
            $t->string('new_value', 1)->nullable();
            $t->string('note', 160)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['org_id', 'batch']);
        });
        DB::statement('ALTER TABLE access_changes ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE access_changes FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON access_changes USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE access_changes ADD CONSTRAINT access_changes_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('access_changes');
    }
};
