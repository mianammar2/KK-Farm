<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-229: KK Farms unified classification, phase 1 (age and teeth).
 *
 * - Age stages are defined by where each one starts, in days or calendar months: YKD from birth, KID from day 15,
 *   GRW from day 60, ADT from 14 months, MAT from 48 months. A stage lasts until the next one starts, so stages can
 *   never overlap or leave gaps.
 * - Teeth are a separate, examined fact (dental codes MLT, T02, T04, T06, T08; no exam = UNK), never assumed from age.
 *   A Dental Condition (normal, worn, broken, missing, abnormal) is kept apart from the incisor count.
 * - Every teeth examination is kept as its own row (dental_exams, append-only). The goat keeps the latest as a cache.
 */
return new class extends Migration
{
    /** old teeth key => [dental code, condition] */
    private const TEETH_MAP = ['milk' => ['MLT', 'normal'], '2' => ['T02', 'normal'], '4' => ['T04', 'normal'], '6' => ['T06', 'normal'], '8' => ['T08', 'normal'], 'worn' => ['T08', 'worn']];

    /** old stage code => new stage code */
    private const STAGE_MAP = ['ADT-T02' => 'ADT', 'ADT-T04' => 'ADT', 'ADT-T06' => 'ADT', 'ADT-T08' => 'ADT', 'ADT-WBT' => 'MAT'];

    public function up(): void
    {
        Schema::table('age_stages', function (Blueprint $t) {
            $t->unsignedSmallInteger('starts_at')->default(0);
            $t->string('starts_unit', 6)->default('days'); // days | months
        });
        Schema::table('teeth_stages', function (Blueprint $t) {
            $t->string('local_term', 40)->nullable();
        });
        Schema::table('animals', function (Blueprint $t) {
            $t->string('dental_condition', 10)->nullable();
            $t->date('teeth_checked_on')->nullable();
        });

        Schema::create('dental_exams', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->uuid('animal_id');
            $t->date('examined_on');
            $t->string('dental_code', 8)->nullable(); // null = not examined / cleared
            $t->string('condition', 10)->nullable();
            $t->string('source', 12)->default('manual'); // manual | import | registration
            $t->string('recorded_by', 80)->nullable();
            $t->string('notes', 200)->nullable();
            $t->timestamps();
            $t->index(['animal_id', 'examined_on']);
        });
        DB::statement('ALTER TABLE dental_exams ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE dental_exams FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON dental_exams USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE dental_exams ADD CONSTRAINT dental_exams_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
        DB::statement('ALTER TABLE dental_exams ADD CONSTRAINT dental_exams_animal_fk FOREIGN KEY (animal_id) REFERENCES animals(id)');

        foreach (DB::table('organizations')->pluck('id') as $org) {
            \App\Support\Tenant::enter((string) $org);

            // Teeth: old keys become dental codes; "worn / broken" becomes 8 teeth with a worn condition.
            foreach (self::TEETH_MAP as $old => [$code, $condition]) {
                DB::table('animals')->where('teeth', $old)->update(['teeth' => $code, 'dental_condition' => $condition]);
            }
            DB::table('teeth_stages')->delete();
            $i = 0;
            foreach (\App\Support\Teeth::DEFAULTS as $code => $d) {
                DB::table('teeth_stages')->insert(['org_id' => $org, 'key' => $code, 'label' => $d[0], 'local_term' => $d[1], 'from_months' => $d[2], 'to_months' => $d[3], 'estimate_months' => $d[4], 'sort' => $i += 10, 'created_at' => now(), 'updated_at' => now()]);
            }
            // keep any teeth value no default covers as its own stage, so no goat loses its record
            foreach (DB::table('animals')->whereNotNull('teeth')->whereNotIn('teeth', array_keys(\App\Support\Teeth::DEFAULTS))->distinct()->pluck('teeth') as $other) {
                DB::table('teeth_stages')->insert(['org_id' => $org, 'key' => $other, 'label' => $other, 'from_months' => 0, 'estimate_months' => 12, 'sort' => $i += 10, 'created_at' => now(), 'updated_at' => now()]);
            }
            // the examination history starts with what is on record today
            foreach (DB::table('animals')->whereNotNull('teeth')->get(['id', 'teeth', 'dental_condition', 'acquired_on', 'created_at']) as $a) {
                $on = $a->acquired_on ?? substr((string) $a->created_at, 0, 10);
                DB::table('dental_exams')->insert(['org_id' => $org, 'animal_id' => $a->id, 'examined_on' => $on, 'dental_code' => $a->teeth, 'condition' => $a->dental_condition, 'source' => 'import', 'recorded_by' => 'Record before D-229', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('animals')->where('id', $a->id)->update(['teeth_checked_on' => $on]);
            }

            // Age stages: the framework's five stages replace the earlier table; vaccine plans aimed at a stage follow.
            DB::table('age_stages')->delete();
            $i = 0;
            foreach (\App\Support\AgeStages::DEFAULTS as $code => $d) {
                DB::table('age_stages')->insert(['org_id' => $org, 'code' => $code, 'label' => $d[0], 'age_group' => $d[1], 'starts_at' => $d[2], 'starts_unit' => $d[3], 'sort' => $i += 10, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (self::STAGE_MAP as $old => $new) {
                DB::table('care_protocols')->where('applies_to', "stage:{$old}")->update(['applies_to' => "stage:{$new}"]);
            }
            DB::table('organizations')->where('id', $org)->update(['grower_from_months' => 2, 'adult_from_months' => 14]);
            \App\Support\Tenant::leave();
        }

        Schema::table('age_stages', function (Blueprint $t) {
            $t->dropColumn(['from_months', 'to_months', 'teeth_key']);
        });
    }

    public function down(): void
    {
        Schema::table('age_stages', function (Blueprint $t) {
            $t->decimal('from_months', 5, 1)->default(0);
            $t->decimal('to_months', 5, 1)->nullable();
            $t->string('teeth_key', 8)->nullable();
            $t->dropColumn(['starts_at', 'starts_unit']);
        });
        Schema::drop('dental_exams');
        Schema::table('animals', fn (Blueprint $t) => $t->dropColumn(['dental_condition', 'teeth_checked_on']));
        Schema::table('teeth_stages', fn (Blueprint $t) => $t->dropColumn('local_term'));
    }
};
