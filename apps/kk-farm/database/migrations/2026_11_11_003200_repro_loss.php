<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-206: journey Q Reproductive Loss Investigation. One case per abortion, early loss, stillbirth or abnormal kidding:
 * containment (isolation, trained handling of the material), samples and lab, the vet's diagnosis, biosecurity and the
 * breeding decision for the doe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('biohazard_trained')->default(false); // may handle aborted material under vet guidance
        });
        Schema::create('repro_loss_cases', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->unsignedInteger('case_no');
            $t->foreignId('kidding_id')->nullable()->constrained('kiddings');
            $t->foreignUuid('dam_id')->constrained('animals');
            $t->foreignUuid('sire_id')->nullable()->constrained('animals');
            $t->foreignId('pen_id')->nullable()->constrained('pens'); // where it happened
            $t->string('loss_type', 12); // abortion | early_loss | stillbirth | premature | mixed
            $t->date('occurred_on');
            $t->unsignedSmallInteger('gestation_days')->nullable();
            $t->unsignedSmallInteger('kids_lost')->default(0);
            $t->string('status', 12)->default('open'); // open | contained | sampled | diagnosed | closed
            $t->timestamp('isolated_at')->nullable();
            $t->foreignId('isolation_pen_id')->nullable()->constrained('pens');
            $t->string('material_handled_by')->nullable();
            $t->boolean('ppe_used')->nullable();
            $t->string('material_note', 500)->nullable();
            $t->date('sampled_on')->nullable();
            $t->string('samples', 200)->nullable(); // fetus, placenta, blood, vaginal swab
            $t->string('lab', 120)->nullable();
            $t->bigInteger('lab_cost_minor')->nullable();
            $t->foreignId('lab_ledger_entry_id')->nullable()->constrained('ledger_entries');
            $t->string('lab_result', 1000)->nullable();
            $t->boolean('zoonotic_suspected')->default(false);
            $t->foreignId('health_case_id')->nullable()->constrained('health_cases'); // restricts the pen while suspected
            $t->string('diagnosis', 1000)->nullable();
            $t->string('diagnosed_by')->nullable();
            $t->timestamp('diagnosed_at')->nullable();
            $t->string('breeding', 12)->nullable(); // rebreed | delay | do_not_breed
            $t->date('breed_after')->nullable();
            $t->string('prevention', 1000)->nullable();
            $t->boolean('cluster')->default(false);
            $t->string('closed_by')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('opened_by');
            $t->timestamps();
        });
        DB::statement('ALTER TABLE repro_loss_cases ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE repro_loss_cases FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON repro_loss_cases USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE repro_loss_cases ADD CONSTRAINT repro_loss_cases_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
    }

    public function down(): void
    {
        Schema::drop('repro_loss_cases');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('biohazard_trained'));
    }
};
