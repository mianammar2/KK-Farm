<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-221: the farm's age stages (ERP code, age category, age range, expected teeth), e.g. ADT-T04 · Adult · 18–24 months
 * · 4 Permanent Teeth. Worked out from the age, never stored on the goat. Each stage counts as Kid, Grower or Adult for
 * feeding and the goat category. Existing farms' teeth stages move to the KK Farms names and ranges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('age_stages', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('code', 12);
            $t->string('label', 40);
            $t->string('age_group', 8); // kid | grower | adult
            $t->decimal('from_months', 5, 1)->default(0);
            $t->decimal('to_months', 5, 1)->nullable(); // null = and older
            $t->string('teeth_key', 8)->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->unique(['org_id', 'code']);
        });
        DB::statement('ALTER TABLE age_stages ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE age_stages FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON age_stages USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE age_stages ADD CONSTRAINT age_stages_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');

        foreach (DB::table('organizations')->pluck('id') as $org) {
            \App\Support\Tenant::enter($org);
            foreach (\App\Support\Teeth::DEFAULTS as $key => $d) {
                DB::table('teeth_stages')->where('key', (string) $key)->update(['label' => $d[0], 'from_months' => $d[1], 'to_months' => $d[2], 'estimate_months' => $d[3], 'updated_at' => now()]);
            }
            \App\Support\Tenant::leave();
        }
    }

    public function down(): void
    {
        Schema::drop('age_stages');
    }
};
