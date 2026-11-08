<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-073: workbook "Health Cases" and "Treatments".
 *
 * - A health case is one illness or injury episode (status Open → Under Treatment → Monitoring → Recovered/Closed,
 *   or Deceased). Its status is current state; every change is also written to the goat's lifecycle.
 * - Treatments are append-only doses under a case. Meat and milk clear dates are typed by the veterinarian,
 *   never calculated (D-006). A goat's withdrawal = the latest clear date of all its treatments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_cases', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->unsignedInteger('case_no');
            $t->foreignUuid('animal_id')->constrained('animals');
            $t->date('onset_on');
            $t->string('condition', 120);
            $t->text('symptoms')->nullable();
            $t->string('severity', 12); // low | moderate | high | emergency
            $t->boolean('isolated')->default(false);
            $t->string('status', 20)->default('open'); // open | under_treatment | monitoring | recovered | closed | deceased
            $t->string('vet', 80)->nullable();
            $t->text('diagnosis')->nullable();
            $t->date('resolved_on')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('report_id')->nullable()->constrained('health_records'); // the concern it came from
            $t->string('recorded_by');
            $t->timestamps();
            $t->unique(['org_id', 'case_no']);
            $t->index(['animal_id', 'status']);
        });

        Schema::create('treatments', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('health_case_id')->constrained();
            $t->foreignUuid('animal_id')->constrained('animals');
            $t->date('given_on');
            $t->string('product', 120);
            $t->string('dose', 40); // e.g. "5 ml", "2 tablets"
            $t->string('route', 12); // oral | injectable | topical | other
            $t->string('frequency', 60)->nullable();
            $t->unsignedSmallInteger('days_prescribed')->nullable();
            $t->date('next_dose_on')->nullable();
            $t->date('meat_clear_on')->nullable();
            $t->date('milk_clear_on')->nullable();
            $t->unsignedBigInteger('cost_minor')->nullable();
            $t->text('notes')->nullable();
            $t->string('recorded_by');
            $t->timestamps();
            $t->index(['animal_id', 'meat_clear_on']);
            $t->index(['animal_id', 'milk_clear_on']);
        });

        foreach (['health_cases', 'treatments'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        Schema::drop('treatments');
        Schema::drop('health_cases');
    }
};
