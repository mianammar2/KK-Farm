<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-095: routine health checkups (every 14 days by default, or any time): temperature, heart and breathing rate, body
 * condition, weight, an ultrasound for does, findings and the outcome. A pregnant ultrasound updates Breeding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_checkups', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignUuid('animal_id')->constrained('animals');
            $t->date('checked_on');
            $t->decimal('temperature_f', 4, 1)->nullable();
            $t->unsignedSmallInteger('heart_rate')->nullable(); // beats a minute
            $t->unsignedSmallInteger('respiration_rate')->nullable(); // breaths a minute
            $t->unsignedTinyInteger('body_score')->nullable();
            $t->decimal('weight_kg', 6, 2)->nullable();
            $t->string('ultrasound', 14)->nullable(); // pregnant | not_pregnant | inconclusive (does only)
            $t->unsignedTinyInteger('fetuses')->nullable();
            $t->decimal('months_pregnant', 3, 1)->nullable();
            $t->text('findings')->nullable();
            $t->string('outcome', 10); // healthy | monitor | sick
            $t->foreignId('health_case_id')->nullable()->constrained('health_cases');
            $t->foreignId('mating_id')->nullable()->constrained('matings');
            $t->string('examiner');
            $t->timestamps();
            $t->index(['animal_id', 'checked_on']);
        });
        DB::statement('ALTER TABLE health_checkups ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE health_checkups FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON health_checkups USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE health_checkups ADD CONSTRAINT health_checkups_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');

        Schema::table('organizations', fn (Blueprint $t) => $t->unsignedSmallInteger('checkup_interval_days')->default(14));
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('checkup_interval_days'));
        Schema::drop('health_checkups');
    }
};
