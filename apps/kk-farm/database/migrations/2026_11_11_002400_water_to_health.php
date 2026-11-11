<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-198: journey I Water-to-Animal Health. A water problem (interruption, shortage, contamination, failed inspection)
 * is one incident from start to verified fix: approved safe supply, repair job, welfare case, vet health check,
 * restoration, outage minutes and cost. Water lab tests bring contamination in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('water_tests', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('water_point_id')->constrained('water_points');
            $t->date('sampled_on');
            $t->string('lab', 120)->nullable();
            $t->decimal('ph', 4, 2)->nullable();
            $t->unsignedInteger('tds_ppm')->nullable();
            $t->unsignedInteger('coliform_cfu')->nullable(); // per 100 ml
            $t->decimal('nitrate_mg_l', 7, 2)->nullable();
            $t->string('verdict', 8); // safe | unsafe
            $t->string('notes', 500)->nullable();
            $t->bigInteger('cost_minor')->nullable();
            $t->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $t->string('recorded_by');
            $t->timestamps();
        });
        DB::statement('ALTER TABLE water_tests ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE water_tests FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON water_tests USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE water_tests ADD CONSTRAINT water_tests_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');

        Schema::table('environment_issues', function (Blueprint $t) {
            $t->string('problem', 14)->nullable(); // interruption | shortage | contamination | inspection
            $t->json('point_ids')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->string('supply_source', 12)->nullable(); // tanker | borehole | stored | other
            $t->timestamp('supply_at')->nullable();
            $t->string('supply_by')->nullable();
            $t->bigInteger('supply_cost_minor')->nullable();
            $t->foreignId('supply_ledger_id')->nullable()->constrained('ledger_entries');
            $t->string('supply_approved_by')->nullable();
            $t->timestamp('supply_approved_at')->nullable();
            $t->timestamp('restored_at')->nullable();
            $t->foreignId('welfare_case_id')->nullable()->constrained('welfare_cases');
            $t->unsignedInteger('goats_exposed')->nullable();
            $t->unsignedInteger('goats_ill')->nullable();
            $t->string('health_outcome', 500)->nullable();
            $t->string('health_checked_by')->nullable();
            $t->timestamp('health_checked_at')->nullable();
            $t->foreignId('water_test_id')->nullable()->constrained('water_tests');
        });
        Schema::table('maintenance_jobs', function (Blueprint $t) {
            $t->foreignId('environment_issue_id')->nullable()->constrained('environment_issues');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_jobs', fn (Blueprint $t) => $t->dropConstrainedForeignId('environment_issue_id'));
        Schema::table('environment_issues', function (Blueprint $t) {
            $t->dropConstrainedForeignId('supply_ledger_id');
            $t->dropConstrainedForeignId('welfare_case_id');
            $t->dropConstrainedForeignId('water_test_id');
            $t->dropColumn(['problem', 'point_ids', 'started_at', 'supply_source', 'supply_at', 'supply_by', 'supply_cost_minor', 'supply_approved_by', 'supply_approved_at',
                'restored_at', 'goats_exposed', 'goats_ill', 'health_outcome', 'health_checked_by', 'health_checked_at']);
        });
        Schema::drop('water_tests');
    }
};
