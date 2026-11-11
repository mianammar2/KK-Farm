<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-203: journey N Transport-to-Delivery. Approved vehicles, a trip per sale order with gate pass, fitness to travel
 * per goat, welfare checks on the road, delivery receipt and customer claims.
 */
return new class extends Migration
{
    private const TABLES = ['vehicles', 'transport_trips', 'transport_checks', 'transport_claims'];

    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('reg_no', 30);
            $t->string('kind', 12); // pickup | truck | trailer | other
            $t->unsignedSmallInteger('capacity_head');
            $t->string('ownership', 8)->default('hired'); // own | hired
            $t->string('driver', 80)->nullable();
            $t->string('phone', 30)->nullable();
            $t->date('inspected_on')->nullable();
            $t->string('inspected_by')->nullable();
            $t->json('inspection')->nullable(); // clean, ventilation, non-slip floor, partitions, ramp, water
            $t->boolean('approved')->default(false);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['org_id', 'reg_no']);
        });
        Schema::create('transport_trips', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('trip_no', 20);
            $t->foreignId('sale_order_id')->unique()->constrained('sale_orders');
            $t->foreignId('vehicle_id')->constrained('vehicles');
            $t->string('driver', 80);
            $t->string('destination', 160);
            $t->unsignedSmallInteger('distance_km')->nullable();
            $t->string('status', 12)->default('planned'); // planned | in_transit | delivered
            $t->string('gate_pass_no', 20)->nullable();
            $t->timestamp('departed_at')->nullable();
            $t->unsignedSmallInteger('head_loaded')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->unsignedSmallInteger('head_received')->nullable();
            $t->string('received_by', 120)->nullable();
            $t->string('receipt_notes', 500)->nullable();
            $t->string('created_by');
            $t->timestamps();
        });
        Schema::create('transport_checks', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('trip_id')->constrained('transport_trips');
            $t->timestamp('checked_at');
            $t->string('location', 120)->nullable();
            $t->unsignedSmallInteger('down')->default(0);    // lying down / unable to stand
            $t->unsignedSmallInteger('injured')->default(0);
            $t->unsignedSmallInteger('dead')->default(0);
            $t->boolean('watered')->default(false);
            $t->decimal('temp_c', 4, 1)->nullable();
            $t->string('notes', 500)->nullable();
            $t->string('checked_by');
            $t->timestamps();
        });
        Schema::create('transport_claims', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('trip_id')->constrained('transport_trips');
            $t->string('kind', 10); // death | injury | shortage | other
            $t->unsignedSmallInteger('head')->default(0);
            $t->string('details', 500);
            $t->bigInteger('claimed_minor')->nullable();
            $t->string('status', 10)->default('open'); // open | accepted | rejected
            $t->bigInteger('credit_minor')->nullable();
            $t->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $t->string('decided_by')->nullable();
            $t->string('decision_note', 300)->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('opened_by');
            $t->timestamps();
        });
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
        Schema::table('sale_order_lines', function (Blueprint $t) {
            $t->boolean('fit_ok')->nullable(); // fitness to travel, checked before loading
            $t->string('fit_by')->nullable();
            $t->timestamp('fit_at')->nullable();
            $t->string('fit_note', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sale_order_lines', fn (Blueprint $t) => $t->dropColumn(['fit_ok', 'fit_by', 'fit_at', 'fit_note']));
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::drop($table);
        }
    }
};
