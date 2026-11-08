<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-075: workbook "Breeding", "Pregnancy Checks", "Kidding" and "Weaning".
 *
 * - Breeding timings are farm settings (gestation 150, check after 30, kidding alert 14, weaning target 84 days).
 * - Pregnancy checks are their own append-only records (several per mating); the mating's outcome follows them.
 * - Kiddings record assisted/attendant/notes; kids link back to their kidding (birth event).
 * - Weanings are per kid: completed (date, weight, method) or postponed (to a date).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->unsignedSmallInteger('gestation_days')->default(150);
            $t->unsignedSmallInteger('pregnancy_check_days')->default(30);
            $t->unsignedSmallInteger('kidding_alert_days')->default(14);
            $t->unsignedSmallInteger('weaning_age_days')->default(84);
        });

        Schema::table('matings', function (Blueprint $t) {
            $t->string('heat_observed', 8)->nullable(); // yes | no | unknown
            $t->unsignedSmallInteger('service_cycle')->nullable();
            $t->date('check_due_on')->nullable();
            $t->string('handler', 80)->nullable();
            $t->string('notes', 500)->nullable();
        });
        // Existing matings get their check-due date from the default 30 days.
        DB::table('matings')->whereNull('check_due_on')->update(['check_due_on' => DB::raw("mated_on + interval '30 days'")]);

        Schema::table('kiddings', function (Blueprint $t) {
            $t->string('assisted', 12)->default('no'); // no | yes | emergency
            $t->string('attendant', 80)->nullable();
            $t->string('notes', 500)->nullable();
        });

        Schema::create('pregnancy_checks', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('mating_id')->constrained();
            $t->foreignUuid('dam_id')->constrained('animals');
            $t->date('checked_on');
            $t->string('method', 20); // ultrasound | blood_test | vet_exam | other
            $t->string('result', 20); // pregnant | not_pregnant | inconclusive | pregnancy_loss
            $t->unsignedTinyInteger('fetuses')->nullable();
            $t->date('next_check_on')->nullable();
            $t->string('examiner');
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->index(['mating_id', 'checked_on']);
        });

        Schema::create('weanings', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignUuid('kid_id')->constrained('animals');
            $t->foreignUuid('dam_id')->nullable()->constrained('animals');
            $t->string('status', 12); // completed | postponed
            $t->date('weaned_on')->nullable();
            $t->date('postponed_to')->nullable();
            $t->decimal('weight_kg', 6, 2)->nullable();
            $t->string('method', 20)->nullable(); // gradual | separate_pen | other
            $t->string('handler');
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->index(['kid_id', 'status']);
        });

        foreach (['pregnancy_checks', 'weanings'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        Schema::drop('weanings');
        Schema::drop('pregnancy_checks');
        Schema::table('kiddings', fn (Blueprint $t) => $t->dropColumn(['assisted', 'attendant', 'notes']));
        Schema::table('matings', fn (Blueprint $t) => $t->dropColumn(['heat_observed', 'service_cycle', 'check_due_on', 'handler', 'notes']));
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['gestation_days', 'pregnancy_check_days', 'kidding_alert_days', 'weaning_age_days']));
    }
};
