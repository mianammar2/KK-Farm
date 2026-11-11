<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-210: App 14 Training (handling, biosecurity, food hygiene, first aid…) and Skills & authorisations with evidence and expiry. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_trainings', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('employee_id')->constrained('employees');
            $t->string('topic', 20);
            $t->string('title', 160)->nullable();
            $t->date('trained_on');
            $t->string('trainer', 120)->nullable();
            $t->date('expires_on')->nullable();
            $t->string('certificate_ref', 80)->nullable();
            $t->string('recorded_by');
            $t->timestamps();
            $t->index(['employee_id', 'topic']);
        });
        Schema::create('staff_skills', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('employee_id')->constrained('employees');
            $t->string('skill', 24);
            $t->string('level', 12)->default('competent'); // trainee | competent | supervisor
            $t->string('evidence', 200)->nullable();       // licence number, assessment, certificate
            $t->date('assessed_on');
            $t->date('valid_until')->nullable();
            $t->string('assessed_by');
            $t->string('status', 10)->default('active'); // active | revoked
            $t->string('revoked_by')->nullable();
            $t->string('revoke_reason', 300)->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'skill']);
        });
        foreach (['staff_trainings', 'staff_skills'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        Schema::drop('staff_skills');
        Schema::drop('staff_trainings');
    }
};
