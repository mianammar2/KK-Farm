<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-195: V3 cross-app journey F Lactation-to-Milk-to-Cash (flow 29): customer milk orders, dispatch storage / transport
 * checks (temperature, clean container, vehicle), milk invoices on cash or credit, and the farm's maximum dispatch temperature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('order_no', 20);
            $t->foreignId('customer_id')->nullable()->constrained('customers');
            $t->string('buyer', 120)->nullable();
            $t->decimal('litres', 8, 2);
            $t->bigInteger('price_per_litre_minor');
            $t->date('wanted_on');
            $t->string('terms', 8)->default('cash'); // cash | credit
            $t->unsignedSmallInteger('credit_days')->nullable();
            $t->string('status', 10)->default('open'); // open | fulfilled | cancelled
            $t->string('notes', 300)->nullable();
            $t->string('created_by');
            $t->timestamps();
            $t->unique(['org_id', 'order_no']);
        });
        DB::statement('ALTER TABLE milk_orders ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE milk_orders FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON milk_orders USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE milk_orders ADD CONSTRAINT milk_orders_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
        Schema::table('milk_dispatches', function (Blueprint $t) {
            $t->foreignId('order_id')->nullable()->constrained('milk_orders');
            $t->decimal('dispatch_temp_c', 4, 1)->nullable();
            $t->boolean('container_ok')->nullable();
            $t->boolean('vehicle_ok')->nullable();
            $t->string('invoice_no', 20)->nullable();
            $t->date('due_on')->nullable();
            $t->timestamp('collected_at')->nullable();
        });
        Schema::table('organizations', fn (Blueprint $t) => $t->decimal('milk_dispatch_max_c', 4, 1)->default(7));
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('milk_dispatch_max_c'));
        Schema::table('milk_dispatches', function (Blueprint $t) {
            $t->dropConstrainedForeignId('order_id');
            $t->dropColumn(['dispatch_temp_c', 'container_ok', 'vehicle_ok', 'invoice_no', 'due_on', 'collected_at']);
        });
        Schema::drop('milk_orders');
    }
};
