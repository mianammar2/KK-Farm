<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-185: App 19 Animal Welfare & Husbandry (V3 flow 19): daily welfare / mobility checks, welfare cases with triage,
 * actions (vet referral, hoof / handling / habitat, follow-up, escalation) and closure with evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('welfare_cases', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->unsignedInteger('case_no');
            $t->foreignUuid('animal_id')->nullable()->constrained('animals');
            $t->foreignId('pen_id')->nullable()->constrained('pens');
            $t->date('opened_on');
            $t->string('issue', 24);
            $t->string('severity', 10);
            $t->string('triage', 1000)->nullable();
            $t->string('status', 14)->default('open'); // open | vet_referral | action | follow_up | escalated | closed
            $t->foreignId('health_record_id')->nullable()->constrained('health_records');
            $t->string('opened_by');
            $t->string('closed_by')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('closure_evidence', 1000)->nullable();
            $t->timestamps();
        });
        Schema::create('welfare_checks', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignUuid('animal_id')->nullable()->constrained('animals');
            $t->foreignId('pen_id')->nullable()->constrained('pens');
            $t->date('checked_on');
            $t->unsignedTinyInteger('bcs')->nullable();        // 1–5
            $t->string('behaviour', 12)->default('normal');    // normal | dull | restless | aggressive | isolated
            $t->string('injury', 10)->default('none');         // none | minor | serious
            $t->unsignedTinyInteger('lameness')->default(0);   // 0 sound … 3 severe
            $t->boolean('pain')->default(false);
            $t->string('notes', 500)->nullable();
            $t->date('next_check_on')->nullable();
            $t->foreignId('case_id')->nullable()->constrained('welfare_cases');
            $t->string('checked_by');
            $t->timestamps();
        });
        Schema::create('welfare_actions', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('case_id')->constrained('welfare_cases');
            $t->string('kind', 14); // vet_referral | hoof | handling | habitat | follow_up | escalate | close
            $t->string('notes', 1000)->nullable();
            $t->boolean('improved')->nullable();
            $t->string('done_by');
            $t->date('done_on');
            $t->timestamps();
        });
        foreach (['welfare_cases', 'welfare_checks', 'welfare_actions'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        Schema::drop('welfare_actions');
        Schema::drop('welfare_checks');
        Schema::drop('welfare_cases');
    }
};
