<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-202: App 22 Fodder & Pasture (optional, off by default) and journey M Fodder-to-TMR. Fields, crops with their
 * input costs and chemical pre-harvest intervals, harvest lots with measured dry matter and quality tests, released
 * into feed stock only by someone other than the harvester.
 */
return new class extends Migration
{
    private const TABLES = ['fodder_fields', 'fodder_crops', 'fodder_inputs', 'fodder_lots'];

    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->boolean('fodder_enabled')->default(false);
            $t->unsignedSmallInteger('aflatoxin_limit_ppb')->default(20);
        });
        Schema::create('fodder_fields', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('name', 80);
            $t->decimal('area_acres', 8, 2);
            $t->string('location', 160)->nullable();
            $t->string('soil', 60)->nullable();
            $t->string('irrigation', 20)->nullable(); // canal | tubewell | rain | drip
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('fodder_crops', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('field_id')->constrained('fodder_fields');
            $t->string('crop', 30);
            $t->string('variety', 60)->nullable();
            $t->decimal('area_acres', 8, 2);
            $t->date('sown_on');
            $t->date('expected_harvest_on')->nullable();
            $t->unsignedInteger('expected_yield_kg'); // fresh, for spreading the crop cost over its cuts
            $t->string('status', 10)->default('growing'); // growing | closed
            $t->string('notes', 500)->nullable();
            $t->string('created_by');
            $t->timestamps();
        });
        Schema::create('fodder_inputs', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('crop_id')->constrained('fodder_crops');
            $t->string('kind', 12); // seed | fertiliser | chemical | labour | water | fuel | other
            $t->date('applied_on');
            $t->string('product', 120)->nullable();
            $t->decimal('qty', 10, 2)->nullable();
            $t->string('unit', 12)->nullable();
            $t->bigInteger('cost_minor')->nullable();
            $t->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $t->unsignedSmallInteger('phi_days')->nullable(); // pre-harvest interval of a pesticide / herbicide
            $t->date('safe_from')->nullable();
            $t->string('recorded_by');
            $t->timestamps();
        });
        Schema::create('fodder_lots', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->unsignedInteger('lot_no');
            $t->foreignId('crop_id')->constrained('fodder_crops');
            $t->date('harvested_on');
            $t->string('form', 10); // green_chop | silage | hay | haylage
            $t->decimal('fresh_kg', 12, 2);
            $t->boolean('final_cut')->default(false);
            $t->decimal('dm_pct', 5, 2)->nullable();
            $t->string('dm_method', 10)->nullable(); // oven | microwave | lab | nir
            $t->boolean('mould')->nullable();
            $t->boolean('smell_ok')->nullable();
            $t->string('nitrate', 10)->default('untested'); // untested | low | medium | high
            $t->decimal('aflatoxin_ppb', 7, 2)->nullable();
            $t->decimal('cp_pct', 5, 2)->nullable();
            $t->string('test_lab', 120)->nullable();
            $t->string('tested_by')->nullable();
            $t->string('status', 10)->default('quarantine'); // quarantine | released | rejected
            $t->string('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->bigInteger('cost_minor')->nullable();
            $t->bigInteger('cost_per_kg_minor')->nullable();
            $t->bigInteger('cost_per_kg_dm_minor')->nullable();
            $t->foreignId('feed_item_id')->nullable()->constrained('feed_items');
            $t->foreignId('stock_movement_id')->nullable()->constrained('feed_stock_movements');
            $t->string('harvested_by');
            $t->timestamps();
        });
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::drop($table);
        }
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['fodder_enabled', 'aflatoxin_limit_ppb']));
    }
};
