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

    /** The org bound by set()/clear(), so current() needs no query (it was asked ~800 times per dashboard). */
    private static ?string $current = null;

    private static bool $known = false;

    /** @var array<string, mixed> values worked out once per request for the current org (D-088) */
    private static array $memo = [];

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
        self::remember($orgId);
    }

    public static function clear(): void
    {
        DB::select("select set_config('app.current_org', '', false)");
        self::remember(null);
    }

    /** The organization bound to this session, or null. */
    public static function current(): ?string
    {
        if (! self::$known) {
            self::remember(DB::selectOne('select app_current_org() as v')->v);
        }

        return self::$current;
    }

    /** The current organization row, read once per request. */
    public static function org(): ?\App\Models\Organization
    {
        $id = self::current();

        return $id ? self::memo('org', fn () => \App\Models\Organization::find($id)) : null;
    }

    /**
     * D-088: work a value out once per request (and org). Cleared when the org changes, at the start and end of every
     * request, and whenever a model is saved or deleted, so it never serves a value older than the data.
     */
    public static function memo(string $key, \Closure $make): mixed
    {
        return array_key_exists($key, self::$memo) ? self::$memo[$key] : (self::$memo[$key] = $make());
    }

    public static function flush(): void
    {
        self::$memo = [];
    }

    /** Forget everything cached (tests: each test starts with a fresh database session). */
    public static function forget(): void
    {
        self::$memo = [];
        self::$known = false;
        self::$current = null;
        self::$saved = null;
    }

    private static function remember(?string $orgId): void
    {
        self::$current = $orgId ?: null;
        self::$known = true;
        self::$memo = [];
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
