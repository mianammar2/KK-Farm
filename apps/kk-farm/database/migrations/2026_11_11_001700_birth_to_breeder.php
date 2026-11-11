<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-191: V3 cross-app journey B Birth-to-Breeder (flow 25): growth plans (target weight by age, per breed or farm-wide),
 * growth interventions (growth or health below target → nutrition / veterinary intervention → follow-up) and the
 * replacement-breeder or sale-stock selection (append-only decisions with the eligibility checks at the time).
 */
return new class extends Migration
{
    private const TABLES = ['growth_targets', 'growth_interventions', 'breeder_selections'];

    public function up(): void
    {
        Schema::create('growth_targets', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('breed_id')->nullable()->constrained('breeds'); // null = every breed
            $t->unsignedSmallInteger('age_days');
            $t->decimal('target_kg', 6, 2);
            $t->timestamps();
        });
        Schema::create('growth_interventions', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignUuid('animal_id')->constrained('animals');
            $t->string('kind', 12);               // nutrition | veterinary
            $t->string('reason', 300);
            $t->string('action', 500)->nullable();
            $t->decimal('weight_kg', 6, 2)->nullable();
            $t->decimal('target_kg', 6, 2)->nullable();
            $t->date('opened_on');
            $t->date('follow_up_on');
            $t->string('status', 14)->default('open'); // open | improved | not_improved
            $t->decimal('result_kg', 6, 2)->nullable();
            $t->string('opened_by');
            $t->string('closed_by')->nullable();
            $t->date('closed_on')->nullable();
            $t->timestamps();
        });
        Schema::create('breeder_selections', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignUuid('animal_id')->constrained('animals');
            $t->string('decision', 12);           // replacement | sale | keep
            $t->string('reason', 500)->nullable();
            $t->jsonb('checks');                  // eligibility at the time of the decision
            $t->boolean('override')->default(false);
            $t->string('decided_by');
            $t->date('decided_on');
            $t->timestamps();
        });
        Schema::table('organizations', function (Blueprint $t) {
            $t->unsignedSmallInteger('selection_age_days')->default(180);
            $t->unsignedTinyInteger('growth_alert_pct')->default(85);
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
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['selection_age_days', 'growth_alert_pct']));
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::drop($table);
        }
    }
};
