<?php

use App\Models\Department;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-052: departments per organization. Each user belongs to one; a task is assigned to a department
 * (anyone in it can do it) or to one user. Tasks no longer need the legacy employees table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('name');
            $t->timestamps();
            $t->foreign('org_id')->references('id')->on('organizations');
            $t->unique(['org_id', 'name']);
        });
        DB::statement('ALTER TABLE departments ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE departments FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON departments USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');

        Schema::table('users', fn (Blueprint $t) => $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete());
        Schema::table('tasks', function (Blueprint $t) {
            $t->foreignId('department_id')->nullable()->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
        });

        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            Department::seedDefaults($orgId);
            foreach (Department::ROLE_DEFAULT as $role => $name) {
                $id = DB::table('departments')->where('org_id', $orgId)->where('name', $name)->value('id');
                DB::table('users')->where('org_id', $orgId)->where('role', $role)->whereNull('department_id')->update(['department_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $t->dropConstrainedForeignId('user_id');
            $t->dropConstrainedForeignId('department_id');
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('department_id'));
        Schema::drop('departments');
    }
};
