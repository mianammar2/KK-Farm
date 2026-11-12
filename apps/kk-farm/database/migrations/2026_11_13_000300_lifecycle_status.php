<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-232: classification phase 4. Statuses are independent (a doe can be pregnant and milking at once) and computed
 * from records; every change is kept in status_history with the rule that caused it (append-only). A goat reaching
 * its market weight is only "Market Weight Achieved": "Sale Ready" needs a recorded sale clearance (sale_clearances,
 * append-only), and any blocker (sick, withdrawal, hold) blocks it again at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_history', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->uuid('animal_id');
            $t->string('dimension', 16); // age | teeth | role | breeding | lactation | fattening | health | sale
            $t->string('from_status', 40)->nullable();
            $t->string('to_status', 40);
            $t->string('rule', 40);
            $t->timestamp('changed_at');
            $t->timestamps();
            $t->index(['animal_id', 'dimension', 'changed_at']);
        });
        Schema::create('sale_clearances', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->uuid('animal_id');
            $t->string('decision', 10); // cleared | withdrawn
            $t->json('checks')->nullable();
            $t->string('reason', 500)->nullable();
            $t->string('decided_by', 80);
            $t->date('decided_on');
            $t->timestamps();
            $t->index(['animal_id', 'decided_on']);
        });
        foreach (['status_history', 'sale_clearances'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_animal_fk FOREIGN KEY (animal_id) REFERENCES animals(id)");
        }
        Schema::table('organizations', function (Blueprint $t) {
            $t->decimal('market_weight_male_kg', 5, 1)->default(25);
            $t->decimal('market_weight_female_kg', 5, 1)->default(25);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['market_weight_male_kg', 'market_weight_female_kg']));
        Schema::drop('sale_clearances');
        Schema::drop('status_history');
    }
};
