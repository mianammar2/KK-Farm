<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-189: App 21 Quality, Compliance & Safety (V3 flow 21): quality cases (alarm / audit finding → affected animal, lot or
 * process → immediate risk? → hold / isolate / recall → root cause and evidence → CAPA with owner and date → approve and
 * execute → independent verification → release only if authorised → close and share learning), product holds that block
 * sale and use, the SOP library with revisions, the regulatory register and emergency drills.
 */
return new class extends Migration
{
    private const TABLES = ['quality_cases', 'quality_capas', 'quality_holds', 'sops', 'sop_revisions', 'compliance_items', 'emergency_drills'];

    public function up(): void
    {
        Schema::create('quality_cases', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->unsignedInteger('case_no');
            $t->string('source', 16);            // milk | medicine | feed | audit | complaint | outbreak | incident | other
            $t->string('title', 160);
            $t->string('description', 2000)->nullable();
            $t->string('subject', 12);           // animal | milk_lot | feed_item | medicine | process
            $t->foreignUuid('animal_id')->nullable()->constrained('animals');
            $t->foreignId('milk_lot_id')->nullable()->constrained('milk_lots');
            $t->foreignId('feed_item_id')->nullable()->constrained('feed_items');
            $t->foreignId('medicine_id')->nullable()->constrained('medicines');
            $t->string('batch', 40)->nullable(); // medicine batch
            $t->string('process', 160)->nullable();
            $t->foreignId('incident_id')->nullable()->constrained('incidents');
            $t->boolean('immediate_risk');
            $t->string('severity', 10);
            $t->string('status', 14)->default('open'); // open | contained | investigating | capa | verifying | reopened | verified | closed
            $t->string('root_cause', 2000)->nullable();
            $t->string('evidence', 2000)->nullable();
            $t->string('learning', 2000)->nullable();
            $t->string('opened_by');
            $t->date('opened_on');
            $t->string('closed_by')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('quality_capas', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('case_id')->constrained('quality_cases');
            $t->string('kind', 12);              // corrective | preventive
            $t->string('action', 1000);
            $t->string('owner');
            $t->date('due_on');
            $t->string('status', 12)->default('planned'); // planned | approved | executed | passed | failed
            $t->string('planned_by');
            $t->string('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->string('executed_by')->nullable();
            $t->timestamp('executed_at')->nullable();
            $t->string('execution_notes', 1000)->nullable();
            $t->string('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->string('verification', 1000)->nullable();
            $t->foreignId('replaces_id')->nullable()->constrained('quality_capas'); // a replan after a failed verification
            $t->timestamps();
        });
        Schema::create('quality_holds', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('case_id')->constrained('quality_cases');
            $t->string('kind', 8);               // hold | isolate | recall
            $t->string('subject', 12);
            $t->foreignUuid('animal_id')->nullable()->constrained('animals');
            $t->foreignId('milk_lot_id')->nullable()->constrained('milk_lots');
            $t->foreignId('feed_item_id')->nullable()->constrained('feed_items');
            $t->foreignId('medicine_id')->nullable()->constrained('medicines');
            $t->string('batch', 40)->nullable();
            $t->string('placed_by');
            $t->timestamp('placed_at');
            $t->string('outcome', 10)->nullable(); // released | disposed
            $t->string('released_by')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->string('release_note', 500)->nullable();
            $t->timestamps();
        });
        Schema::create('sops', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('code', 20);
            $t->string('title', 120);
            $t->string('area', 16);              // milking | feed | medicine | biosecurity | welfare | water | dispatch | safety | other
            $t->unsignedInteger('version')->default(0);
            $t->date('review_on')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['org_id', 'code']);
        });
        Schema::create('sop_revisions', function (Blueprint $t) { // append-only: each revision is a new row
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('sop_id')->constrained('sops');
            $t->unsignedInteger('version');
            $t->text('body');
            $t->string('change_note', 500)->nullable();
            $t->foreignId('case_id')->nullable()->constrained('quality_cases'); // revision raised by a CAPA
            $t->string('written_by');
            $t->string('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['sop_id', 'version']);
        });
        Schema::create('compliance_items', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('kind', 14);              // licence | permit | certificate | registration | inspection | insurance | other
            $t->string('name', 120);
            $t->string('authority', 120)->nullable();
            $t->string('reference', 60)->nullable();
            $t->date('issued_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->string('owner')->nullable();
            $t->string('notes', 500)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('emergency_drills', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('scenario', 16);          // fire | outbreak | water_failure | power_failure | flood | heat | recall | injury | other
            $t->date('held_on');
            $t->unsignedInteger('participants')->default(0);
            $t->unsignedInteger('response_minutes')->nullable();
            $t->boolean('passed');
            $t->string('findings', 1000)->nullable();
            $t->date('next_due_on')->nullable();
            $t->foreignId('case_id')->nullable()->constrained('quality_cases'); // a failed drill opens a quality case
            $t->string('recorded_by');
            $t->timestamps();
        });
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        foreach (['emergency_drills', 'compliance_items', 'sop_revisions', 'sops', 'quality_holds', 'quality_capas', 'quality_cases'] as $table) {
            Schema::drop($table);
        }
    }
};
