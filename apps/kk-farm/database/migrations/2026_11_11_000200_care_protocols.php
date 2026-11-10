<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-173: the vet's vaccine and deworming protocols (Veterinary → Configuration): what, for which goats, from what age
 * and how often. The vaccination calendar is worked out from them and from the care already given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_protocols', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('name', 120);
            $t->string('kind', 20);                       // vaccination | deworming | hoof_care | examination | other_care
            $t->foreignId('medicine_id')->nullable()->constrained('medicines');
            $t->decimal('dose_per_goat', 8, 2)->nullable();
            $t->string('applies_to', 20)->default('all'); // all | kids | growers | adults | does | bucks | pregnant
            $t->unsignedSmallInteger('first_age_days')->nullable();
            $t->unsignedSmallInteger('interval_days')->nullable(); // null: once
            $t->string('notes', 500)->nullable();
            $t->boolean('active')->default(true);
            $t->string('created_by');
            $t->timestamps();
        });
        DB::statement('ALTER TABLE care_protocols ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE care_protocols FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON care_protocols USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE care_protocols ADD CONSTRAINT care_protocols_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');

        Schema::table('health_records', fn (Blueprint $t) => $t->foreignId('protocol_id')->nullable()->constrained('care_protocols'));
    }

    public function down(): void
    {
        Schema::table('health_records', fn (Blueprint $t) => $t->dropConstrainedForeignId('protocol_id'));
        Schema::drop('care_protocols');
    }
};
