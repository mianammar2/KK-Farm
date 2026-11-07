<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Goat Register fields from the KK Farms workbook, plus tenant-defined custom fields (D-040).
 *
 * - Typed columns for the workbook fields the app did not have, so reports can use them.
 * - `custom_fields` jsonb holds tenant-defined fields. (Not named `attributes`: that is an Eloquent internal.)
 * - `field_definitions` describes every registration field per organization: label, type, dropdown options,
 *   order. Built-in fields can be relabelled and have their options edited; custom fields can be added freely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animals', function (Blueprint $t) {
            $t->string('animal_class')->nullable();
            $t->string('repro_status')->nullable();
            $t->string('health_flag')->nullable();
            $t->string('ownership')->nullable();
            $t->string('source_farm')->nullable();
            $t->date('acquired_on')->nullable();
            $t->decimal('birth_weight_kg', 5, 2)->nullable();
            $t->bigInteger('purchase_cost_minor')->nullable(); // paisa; currency is the organization's
            $t->text('notes')->nullable();
            $t->jsonb('custom_fields')->default(DB::raw("'{}'::jsonb"));
        });

        Schema::create('field_definitions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('entity')->default('animal');
            $t->string('key');
            $t->string('label');
            $t->string('type'); // text | long_text | number | date | yes_no | select | lookup | scan
            $t->jsonb('options')->nullable(); // [{value, label}] for select
            $t->string('section');
            $t->unsignedInteger('sort')->default(0);
            $t->boolean('required')->default(false);
            $t->boolean('builtin')->default(false);
            $t->boolean('active')->default(true);
            $t->string('help')->nullable();
            $t->timestamps();
            $t->foreign('org_id')->references('id')->on('organizations');
            $t->unique(['org_id', 'entity', 'key']);
        });

        DB::statement('ALTER TABLE field_definitions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE field_definitions FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON field_definitions USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON field_definitions TO kkfarm_app');

        // Organizations that already exist get the standard registration fields too.
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            \App\Support\FieldCatalog::seed($orgId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('field_definitions');
        Schema::table('animals', fn (Blueprint $t) => $t->dropColumn([
            'animal_class', 'repro_status', 'health_flag', 'ownership', 'source_farm', 'acquired_on', 'birth_weight_kg', 'purchase_cost_minor', 'notes', 'custom_fields',
        ]));
    }
};
