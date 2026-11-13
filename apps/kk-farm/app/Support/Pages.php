<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * D-241 / D-243: the one place that decides which pages a person may open. The registry (config/kkfarm_pages.php)
 * holds every page with its default roles; the administrator's changes per role (role_page_access) and per person
 * (user_page_access) go on top. The server (EnsurePage), the menus, the All Apps grid, tab bars, role homes,
 * search, reports and notifications all ask here, so a menu never shows a page that answers 403.
 *
 * Effective access to a page = its module is open to the person AND (person's own tick ?? role's tick ?? default).
 * Locks: 'admin' (Administrator only, never changed), 'vet' (roles fixed; one person may be given it),
 * 'always' (everyone), 'opening' (the address without a tab: opens when any of its tabs does).
 */
final class Pages
{
    /** Addresses every signed-in person may open, not listed in the registry. */
    private const ALWAYS = ['/apps', '/app', '/account', '/notifications', '/media'];

    /** @return array<string, array> the registry by page key */
    public static function registry(): array
    {
        return Tenant::memo('pages.registry', fn () => collect(config('kkfarm_pages'))->keyBy('key')->all());
    }

    /** The registry row for this address: the exact path with the most matching query values, else the nearest parent path. */
    public static function rule(string $path, array $query = []): ?array
    {
        $path = '/'.trim($path, '/');
        $best = null;
        $score = -1;
        foreach (self::registry() as $r) {
            if ($r['path'] !== $path) {
                continue;
            }
            $q = $r['query'] ?? [];
            if (collect($q)->every(fn ($v, $k) => ($query[$k] ?? null) === $v) && count($q) > $score) {
                [$best, $score] = [$r, count($q)];
            }
        }
        if ($best || $path === '/') {
            return $best;
        }
        $parts = explode('/', trim($path, '/'));
        while (count($parts) > 1) {
            array_pop($parts);
            $parent = '/'.implode('/', $parts);
            foreach (self::registry() as $r) {
                if ($r['path'] === $parent && empty($r['query'])) {
                    return $r;
                }
            }
        }

        return null;
    }

    /** May this person open the address? A page missing from the registry is for the Administrator only. */
    public static function allows(User $u, string $path, array $query = []): bool
    {
        $rule = self::rule($path, $query);
        if (! $rule) {
            $first = '/'.(explode('/', trim($path, '/'))[0] ?? '');

            return in_array($first, self::ALWAYS, true) || $u->role === 'ceo';
        }

        return self::allowsKey($u, $rule['key']);
    }

    public static function allowsKey(User $u, string $key): bool
    {
        $r = self::registry()[$key] ?? null;
        if (! $r) {
            return $u->role === 'ceo';
        }

        return match ($r['lock']) {
            'always' => true,
            'admin' => $u->role === 'ceo',
            'opening' => collect(self::registry())->contains(fn ($x) => $x['path'] === $r['path'] && ! empty($x['query']) && self::allowsKey($u, $x['key'])),
            default => $u->canModule($r['module']) && (self::userTicks($u)[$key] ?? self::roleAllows($u->org_id, $u->role, $r)),
        };
    }

    /** The role's tick for a page (its saved change, else the default), before the module and a person's own ticks. */
    public static function roleAllows(?string $orgId, string $role, array $r): bool
    {
        if ($r['lock'] === 'vet') {
            return in_array($role, $r['roles'], true); // the role screen never changes a vet-locked page
        }
        $saved = self::roleTicks($orgId)[$role][$r['key']] ?? null;

        return $saved ?? self::defaultFor($role, $r);
    }

    /**
     * The default: the registry's roles. A role that does not hold the page's module by default (the module was
     * ticked for it later, or for one person) starts with the module's everyday pages: those open to the Farm Manager.
     */
    public static function defaultFor(string $role, array $r): bool
    {
        if (in_array($role, $r['roles'], true)) {
            return true;
        }
        $holdsModule = in_array($role, config("kkfarm.modules.{$r['module']}", []), true);

        return ! $holdsModule && $r['lock'] === null && in_array('manager', $r['roles'], true);
    }

    /** @return array<string, array<string, bool>> role => page key => allowed */
    public static function roleTicks(?string $orgId): array
    {
        return Tenant::memo("pages.roles.$orgId", fn () => DB::table('role_page_access')->get(['role', 'page_key', 'allowed'])
            ->groupBy('role')->map(fn ($rows) => $rows->mapWithKeys(fn ($x) => [$x->page_key => (bool) $x->allowed])->all())->all());
    }

    /** @return array<string, bool> page key => allowed, this person's own ticks */
    public static function userTicks(User $u): array
    {
        return Tenant::memo("pages.user.{$u->id}", fn () => $u->id ? DB::table('user_page_access')->where('user_id', $u->id)->pluck('allowed', 'page_key')->map(fn ($v) => (bool) $v)->all() : []);
    }

    /** May a person's page change this page for them? (vet-locked: yes, by the administrator, for one person) */
    public static function userEditable(array $r): bool
    {
        return in_array($r['lock'], [null, 'vet'], true);
    }

    /** @return list<array{0: string, 1: ?array, 2: bool}> every page with whether this person may open it (for the menus) */
    public static function forUser(User $u): array
    {
        return collect(self::registry())->map(fn ($r) => [$r['path'], $r['query'] ?: null, self::allowsKey($u, $r['key'])])->values()->all();
    }

    /** Where this person lands after signing in and when a page is closed to them (RBAC §6). */
    public static function home(User $u): string
    {
        return in_array($u->role, ['ceo', 'manager'], true) && self::allows($u, '/') ? '/' : '/home';
    }
}
