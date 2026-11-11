<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-193: V3 cross-app journey D Disease-to-Recovery (flow 27): triage (severity + contagion risk), immediate isolation
 * and emergency alert, movement restriction of the affected pen until the vet clears it, lab investigations, clearance
 * into suitable housing, true case cost (medicine + lab + labour) and outbreak reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbreaks', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('code', 20);
            $t->string('condition', 120);
            $t->date('opened_on');
            $t->date('closed_on')->nullable();
            $t->string('status', 8)->default('open'); // open | closed
            $t->unsignedInteger('population')->default(0); // goats at risk when declared
            $t->text('report')->nullable();
            $t->string('opened_by');
            $t->string('closed_by')->nullable();
            $t->timestamps();
            $t->unique(['org_id', 'code']);
        });
        DB::statement('ALTER TABLE outbreaks ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE outbreaks FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON outbreaks USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE outbreaks ADD CONSTRAINT outbreaks_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');

        Schema::table('health_cases', function (Blueprint $t) {
            $t->string('contagion', 10)->default('none');   // none | suspected | confirmed
            $t->boolean('emergency')->default(false);
            $t->foreignId('restricted_pen_id')->nullable()->constrained('pens'); // affected lot held until the vet clears it
            $t->foreignId('outbreak_id')->nullable()->constrained('outbreaks');
            $t->date('review_on')->nullable();
            $t->string('lab_test', 120)->nullable();
            $t->date('lab_sampled_on')->nullable();
            $t->string('lab_result', 500)->nullable();
            $t->bigInteger('lab_cost_minor')->nullable();
            $t->foreignId('lab_ledger_entry_id')->nullable()->constrained('ledger_entries');
            $t->decimal('labour_hours', 6, 1)->nullable();
            $t->string('cleared_by')->nullable();
            $t->foreignId('cleared_to_pen_id')->nullable()->constrained('pens');
        });
        Schema::table('diseases', fn (Blueprint $t) => $t->boolean('contagious')->default(false));
        Schema::table('organizations', fn (Blueprint $t) => $t->bigInteger('labour_rate_minor')->default(25000)); // PKR per hour for case cost
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('labour_rate_minor'));
        Schema::table('diseases', fn (Blueprint $t) => $t->dropColumn('contagious'));
        Schema::table('health_cases', function (Blueprint $t) {
            $t->dropConstrainedForeignId('restricted_pen_id');
            $t->dropConstrainedForeignId('outbreak_id');
            $t->dropConstrainedForeignId('lab_ledger_entry_id');
            $t->dropConstrainedForeignId('cleared_to_pen_id');
            $t->dropColumn(['contagion', 'emergency', 'review_on', 'lab_test', 'lab_sampled_on', 'lab_result', 'lab_cost_minor', 'labour_hours', 'cleared_by']);
        });
        Schema::drop('outbreaks');
    }
};
