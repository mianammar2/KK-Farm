<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-187: App 18 Fattening & Meat Production (V3 flow 18): finishing batches and the goats in them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fattening_batches', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('code', 20);                    // FTN-2026-0001
            $t->string('name', 120)->nullable();
            $t->foreignId('pen_id')->nullable()->constrained('pens');
            $t->foreignId('ration_plan_id')->nullable()->constrained('ration_plans');
            $t->date('started_on');
            $t->decimal('target_weight_kg', 6, 1);
            $t->unsignedSmallInteger('target_days');
            $t->string('status', 14)->default('active'); // planned | active | target_reached | sale_hold | sale_ready | closed
            $t->string('notes', 500)->nullable();
            $t->string('created_by');
            $t->date('closed_on')->nullable();
            $t->timestamps();
            $t->unique(['org_id', 'code']);
        });
        Schema::create('fattening_batch_animals', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('batch_id')->constrained('fattening_batches');
            $t->foreignUuid('animal_id')->constrained('animals');
            $t->date('joined_on');
            $t->decimal('entry_weight_kg', 6, 2)->nullable();
            $t->bigInteger('entry_cost_minor')->nullable(); // purchase or book value at entry
            $t->string('status', 12)->default('finishing'); // finishing | ready | reserved | sold | died | removed
            $t->string('cleared_by')->nullable();           // vet meat-withdrawal clearance
            $t->timestamp('cleared_at')->nullable();
            $t->foreignId('sale_id')->nullable()->constrained('sales');
            $t->date('left_on')->nullable();
            $t->decimal('exit_weight_kg', 6, 2)->nullable();
            $t->bigInteger('feed_cost_minor')->nullable();  // allocated at exit: basis recorded below (BR-020/032)
            $t->string('cost_basis', 200)->nullable();
            $t->string('exit_reason', 200)->nullable();
            $t->timestamps();
            $t->unique(['batch_id', 'animal_id']);
        });
        foreach (['fattening_batches', 'fattening_batch_animals'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        Schema::drop('fattening_batch_animals');
        Schema::drop('fattening_batches');
    }
};
