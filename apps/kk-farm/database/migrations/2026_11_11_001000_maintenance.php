<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-182: Maintenance & Assets: asset register, repair and preventive maintenance jobs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('code', 30);
            $t->string('name', 120);
            $t->string('category', 20);
            $t->foreignId('shed_id')->nullable()->constrained('sheds');
            $t->date('purchased_on')->nullable();
            $t->bigInteger('cost_minor')->nullable();
            $t->string('status', 14)->default('working'); // working | under_repair | retired
            $t->unsignedSmallInteger('service_every_days')->nullable();
            $t->date('next_service_on')->nullable();
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['org_id', 'code']);
        });
        Schema::create('maintenance_jobs', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('asset_id')->constrained('assets');
            $t->string('kind', 12);                 // repair | preventive | inspection
            $t->date('requested_on');
            $t->string('problem', 1000);
            $t->string('priority', 10)->default('medium');
            $t->string('status', 12)->default('open'); // open | in_progress | done
            $t->string('requested_by');
            $t->date('done_on')->nullable();
            $t->string('work_done', 1000)->nullable();
            $t->bigInteger('cost_minor')->nullable();
            $t->string('done_by')->nullable();
            $t->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $t->timestamps();
        });
        foreach (['assets', 'maintenance_jobs'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        Schema::drop('maintenance_jobs');
        Schema::drop('assets');
    }
};
