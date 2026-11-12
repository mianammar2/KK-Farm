<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-234: minimal goat registry. The farm enters origin, breed, gender, age (birth date, age stage or teeth), purpose,
 * weight and pen; the rest is worked out by rules the owner can change (Settings → Automation Rules):
 * - classification_rules: gender × age stage × purpose ('*' = any) → goat category and default female status;
 * - teeth follow age again (owner's table): dental codes get back-to-back age ranges, so an unexamined goat shows the
 *   teeth for its age (ADT-T04); a recorded teeth check still wins;
 * - Age Category, Goat Category, Female Status, Kid Stage and the estimate flag leave the form (worked out instead).
 */
return new class extends Migration
{
    private const TEETH = ['MLT' => [0, 14, 8], 'T02' => [14, 18, 16], 'T04' => [18, 24, 21], 'T06' => [24, 30, 27], 'T08' => [30, null, 39]];

    private const AUTO_FIELDS = ['age_category', 'animal_class', 'repro_status', 'kid_stage', 'birth_date_is_estimate'];

    public function up(): void
    {
        Schema::create('classification_rules', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('sex', 6);
            $t->string('stage_code', 12);
            $t->string('purpose', 14)->default('*');
            $t->string('animal_class', 24);
            $t->string('female_status', 20)->nullable();
            $t->timestamps();
            $t->unique(['org_id', 'sex', 'stage_code', 'purpose']);
        });
        DB::statement('ALTER TABLE classification_rules ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE classification_rules FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON classification_rules USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE classification_rules ADD CONSTRAINT classification_rules_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');

        foreach (DB::table('organizations')->pluck('id') as $org) {
            \App\Support\Tenant::enter((string) $org);
            foreach (self::TEETH as $key => [$from, $to, $est]) {
                DB::table('teeth_stages')->where('key', $key)->update(['from_months' => $from, 'to_months' => $to, 'estimate_months' => $est, 'updated_at' => now()]);
            }
            DB::table('field_definitions')->where('entity', 'animal')->where('builtin', true)->whereIn('key', self::AUTO_FIELDS)->update(['active' => false, 'updated_at' => now()]);
            DB::table('field_definitions')->where('entity', 'animal')->where('builtin', true)->where('key', 'teeth')
                ->update(['label' => 'Teeth (if checked)', 'help' => 'Optional. Teeth follow the age by themselves; pick only if you looked in the mouth. It also fills in the estimated age.', 'updated_at' => now()]);
            \App\Support\Tenant::leave();
        }
    }

    public function down(): void
    {
        foreach (DB::table('organizations')->pluck('id') as $org) {
            \App\Support\Tenant::enter((string) $org);
            DB::table('field_definitions')->where('entity', 'animal')->where('builtin', true)->whereIn('key', self::AUTO_FIELDS)->update(['active' => true]);
            \App\Support\Tenant::leave();
        }
        Schema::drop('classification_rules');
    }
};
