<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Binds the current organization to the database session so PostgreSQL Row-Level Security can isolate tenants.
 *
 * Two things are needed and both are set per request:
 *  1. `app.current_org`, read by the policies through app_current_org().
 *  2. A non-superuser role. Superusers bypass RLS entirely, so the connection switches to `kkfarm_app`
 *     (config kkfarm.tenancy.db_role). Without a role the policies exist but do nothing.
 *
 * Session-level settings outlive a request on pooled or long-lived workers (Octane, queues). enter() records
 * what it replaced and leave() restores it, so nothing leaks to the next request.
 */
final class Tenant
{
    /** @var array{org: ?string, role: string}|null */
    private static ?array $saved = null;

    public static function enter(?string $orgId): void
    {
        self::$saved ??= [
            'org' => DB::selectOne("select current_setting('app.current_org', true) as v")->v ?: null,
            'role' => DB::selectOne("select current_setting('role') as v")->v,
        ];

        $orgId ? self::set($orgId) : self::clear();

        if ($role = self::role()) {
            DB::statement(sprintf('SET ROLE "%s"', $role));
        }
    }

    public static function leave(): void
    {
        if (self::$saved === null) {
            return;
        }

        ['org' => $org, 'role' => $role] = self::$saved;
        self::$saved = null;

        $role === 'none' ? DB::statement('RESET ROLE') : DB::statement(sprintf('SET ROLE "%s"', self::safe($role)));
        $org ? self::set($org) : self::clear();
    }

    public static function set(string $orgId): void
    {
        DB::select("select set_config('app.current_org', ?, false)", [$orgId]);
    }

    public static function clear(): void
    {
        DB::select("select set_config('app.current_org', '', false)");
    }

    /** The organization bound to this session, or null. */
    public static function current(): ?string
    {
        return DB::selectOne('select app_current_org() as v')->v;
    }

    public static function role(): ?string
    {
        $role = config('kkfarm.tenancy.db_role');

        return $role ? self::safe($role) : null;
    }

    private static function safe(string $role): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $role)) {
            throw new InvalidArgumentException("Unsafe database role name: $role");
        }

        return $role;
    }
}
