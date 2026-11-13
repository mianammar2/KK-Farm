<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-241: KK_FARMS_RBAC.md becomes the role defaults (config/kkfarm.php). Each organisation's saved role ticks
 * (Settings → Access by role) would otherwise override the new defaults, so they are set aside, not deleted:
 * the previous values are kept on the organisation and listed in the activity log for the administrator to review.
 * Per-person ticks (users.module_access / gate_access) are kept as they are and listed too. No user changes role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->jsonb('role_access_before_rbac')->nullable();
        });
        foreach (DB::table('organizations')->get(['id', 'role_modules', 'role_gates']) as $org) {
            \App\Support\Tenant::enter((string) $org->id);
            $before = ['role_modules' => json_decode((string) $org->role_modules, true), 'role_gates' => json_decode((string) $org->role_gates, true)];
            DB::table('organizations')->where('id', $org->id)->update(['role_access_before_rbac' => json_encode($before), 'role_modules' => null, 'role_gates' => null]);
            $own = DB::table('users')->where('org_id', $org->id)->where(fn ($q) => $q->whereNotNull('module_access')->orWhereNotNull('gate_access'))->get(['name', 'module_access', 'gate_access']);
            $note = ($before['role_modules'] || $before['role_gates'] ? 'earlier role ticks were set aside (kept for review)' : 'no earlier role ticks')
                .($own->isEmpty() ? '; no personal ticks' : '; personal ticks kept for review: '.$own->pluck('name')->implode(', '));
            DB::table('activities')->insert(['org_id' => $org->id, 'actor' => 'System', 'role' => 'System', 'action' => "Access rights now follow KK_FARMS_RBAC.md (D-241): $note", 'url' => '/settings/access', 'audience' => 'security', 'created_at' => now(), 'updated_at' => now()]);
            \App\Support\Tenant::leave();
        }
    }

    public function down(): void
    {
        foreach (DB::table('organizations')->whereNotNull('role_access_before_rbac')->get(['id', 'role_access_before_rbac']) as $org) {
            \App\Support\Tenant::enter((string) $org->id);
            $b = json_decode((string) $org->role_access_before_rbac, true);
            DB::table('organizations')->where('id', $org->id)->update(['role_modules' => $b['role_modules'] ? json_encode($b['role_modules']) : null, 'role_gates' => $b['role_gates'] ? json_encode($b['role_gates']) : null]);
            \App\Support\Tenant::leave();
        }
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('role_access_before_rbac'));
    }
};
