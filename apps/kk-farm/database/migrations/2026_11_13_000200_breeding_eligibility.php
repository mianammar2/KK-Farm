<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-231: classification phase 3, breeding eligibility. Qualification is not approval: the system checks the farm's
 * rules (per breed and sex, or farm-wide) and asks for approval; only an authorised person approves a breeder.
 * - breeding_rules: thresholds per breed and sex (breed_id null = every breed).
 * - breeding_approvals: each approval or rejection with the checks at that moment (append-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('breeding_rules', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('breed_id')->nullable()->constrained();
            $t->string('sex', 6); // female | male
            $t->unsignedSmallInteger('min_age_months');
            $t->decimal('min_weight_kg', 5, 1);
            $t->decimal('mature_weight_kg', 5, 1)->nullable(); // null = no mature-weight check
            $t->unsignedTinyInteger('min_mature_pct')->default(65);
            $t->decimal('min_bcs', 3, 1)->nullable(); // null = not checked
            $t->unsignedSmallInteger('weight_max_age_days')->default(30); // a weight older than this does not count
            $t->timestamps();
            $t->unique(['org_id', 'breed_id', 'sex']);
        });
        Schema::create('breeding_approvals', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->uuid('animal_id');
            $t->string('decision', 10); // approved | rejected
            $t->json('checks');
            $t->boolean('repro_exam_passed')->default(false);
            $t->boolean('genetics_approved')->default(false);
            $t->boolean('override')->default(false);
            $t->string('reason', 500)->nullable();
            $t->string('decided_by', 80);
            $t->date('decided_on');
            $t->timestamps();
            $t->index(['animal_id', 'decided_on']);
        });
        foreach (['breeding_rules', 'breeding_approvals'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
        DB::statement('ALTER TABLE breeding_approvals ADD CONSTRAINT breeding_approvals_animal_fk FOREIGN KEY (animal_id) REFERENCES animals(id)');
    }

    public function down(): void
    {
        Schema::drop('breeding_approvals');
        Schema::drop('breeding_rules');
    }
};
