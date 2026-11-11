<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-204: journey O Waste-to-Safe-Disposal. Waste routes whose disposal method is set by a named qualified adviser,
 * disposals with evidence, infection controls and cost, contractor pickups and a second-person audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_streams', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('kind', 14);   // manure | wastewater | sharps | medical | carcass | placenta | feed_waste | containers | general
            $t->string('name', 80);
            $t->string('method', 14); // compost | spread | biogas | sold | contractor | burial | incineration | rendering | lagoon | sharps_box | return
            $t->string('instructions', 1000)->nullable(); // the adviser's handling and biosecurity steps
            $t->string('adviser', 120)->nullable();       // the qualified adviser who set the method
            $t->string('adviser_role', 80)->nullable();   // e.g. district livestock officer, EPA consultant, vet
            $t->date('advised_on')->nullable();
            $t->string('contractor', 120)->nullable();
            $t->string('licence_no', 60)->nullable();
            $t->unsignedSmallInteger('pickup_every_days')->nullable();
            $t->boolean('active')->default(true);
            $t->string('recorded_by');
            $t->timestamps();
        });
        Schema::create('waste_disposals', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('stream_id')->constrained('waste_streams');
            $t->date('disposed_on');
            $t->decimal('qty', 12, 2)->nullable();
            $t->string('unit', 10)->nullable(); // kg | litre | count | trolley | bag
            $t->string('method', 14);
            $t->string('deviation_reason', 300)->nullable(); // a different method than the adviser's: why
            $t->foreignUuid('animal_id')->nullable()->constrained('animals'); // the carcass
            $t->string('evidence_ref', 120)->nullable(); // manifest, receipt, burial-pit reference
            $t->boolean('ppe_used')->default(false);
            $t->boolean('area_disinfected')->default(false);
            $t->bigInteger('cost_minor')->nullable();
            $t->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $t->string('notes', 500)->nullable();
            $t->string('recorded_by');
            $t->string('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->string('audit_note', 300)->nullable();
            $t->timestamps();
        });
        foreach (['waste_streams', 'waste_disposals'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        Schema::drop('waste_disposals');
        Schema::drop('waste_streams');
    }
};
