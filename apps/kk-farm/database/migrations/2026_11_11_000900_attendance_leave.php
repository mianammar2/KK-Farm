<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-181: Employees & HR: daily attendance register and leave requests. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('employee_id')->constrained('employees');
            $t->date('work_on');
            $t->string('status', 10); // present | half_day | absent | leave
            $t->time('check_in')->nullable();
            $t->time('check_out')->nullable();
            $t->string('notes', 300)->nullable();
            $t->string('recorded_by');
            $t->timestamps();
            $t->unique(['employee_id', 'work_on']);
        });
        Schema::create('leave_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('employee_id')->constrained('employees');
            $t->date('from_on');
            $t->date('to_on');
            $t->string('kind', 10);                     // annual | sick | casual | unpaid
            $t->string('reason', 500)->nullable();
            $t->string('status', 10)->default('pending'); // pending | approved | rejected
            $t->string('requested_by');
            $t->string('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
        foreach (['attendances', 'leave_requests'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        Schema::drop('leave_requests');
        Schema::drop('attendances');
    }
};
