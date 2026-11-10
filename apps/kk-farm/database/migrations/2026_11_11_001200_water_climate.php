<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-186: App 20 Water, Climate & Environment (V3 flow 20): water points, environment rounds per shed (water →
 * climate & ventilation → manure, drainage, waste → utility cost), the issues they raise and their resolution.
 */
return new class extends Migration
{
    private const TABLES = ['water_points', 'environment_rounds', 'water_checks', 'environment_issues'];

    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->decimal('heat_stress_thi', 5, 1)->default(80); // BR-022: heat-stress threshold, approved by the vet / farm SOP
        });
        Schema::create('water_points', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('name', 80);
            $t->string('kind', 12); // trough | nipple | bowl | tank | borehole | pipeline
            $t->foreignId('shed_id')->nullable()->constrained('sheds');
            $t->foreignId('pen_id')->nullable()->constrained('pens');
            $t->foreignId('asset_id')->nullable()->constrained('assets');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('environment_rounds', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('shed_id')->nullable()->constrained('sheds');
            $t->dateTime('round_at');
            $t->string('trigger', 12)->default('scheduled'); // scheduled | sensor_alarm | report
            $t->boolean('water_ok');
            $t->decimal('temperature_c', 4, 1)->nullable();
            $t->unsignedTinyInteger('humidity_pct')->nullable();
            $t->decimal('thi', 5, 1)->nullable();
            $t->boolean('ventilation_ok')->default(true);
            $t->boolean('power_ok')->default(true);
            $t->boolean('climate_risk')->default(false);
            $t->boolean('waste_ok')->default(true);
            $t->string('waste_notes', 500)->nullable();
            $t->string('utility_kind', 12)->nullable(); // electricity | water | fuel | solar
            $t->bigInteger('utility_cost_minor')->nullable();
            $t->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $t->string('notes', 500)->nullable();
            $t->string('recorded_by');
            $t->timestamps();
        });
        Schema::create('water_checks', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('round_id')->constrained('environment_rounds');
            $t->foreignId('water_point_id')->constrained('water_points');
            $t->boolean('available');
            $t->string('quality', 10); // clean | dirty | smell | algae | empty
            $t->unsignedSmallInteger('tds_ppm')->nullable();
            $t->timestamps();
        });
        Schema::create('environment_issues', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('round_id')->nullable()->constrained('environment_rounds');
            $t->foreignId('shed_id')->nullable()->constrained('sheds');
            $t->string('kind', 10); // water | heat | power | airflow | waste
            $t->string('severity', 10);
            $t->string('description', 1000);
            $t->string('status', 10)->default('open'); // open | resolved | verified
            $t->foreignId('task_id')->nullable()->constrained('tasks');
            $t->string('resolution', 1000)->nullable();
            $t->string('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->string('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
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
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('heat_stress_thi'));
    }
};
