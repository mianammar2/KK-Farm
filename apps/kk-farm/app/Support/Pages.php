<?php

namespace App\Support;

use App\Models\User;

/**
 * D-241: which pages a person may open (config/kkfarm_pages.php, from KK_FARMS_RBAC.md §8.3). The same rules gate the
 * server (EnsurePage middleware) and the menus (shared to the browser), so a menu never shows a page that answers 403.
 */
final class Pages
{
    /** The rule for this address: the exact path with the most matching query values, else the nearest parent path. */
    public static function rule(string $path, array $query = []): ?array
    {
        $path = '/'.trim($path, '/');
        $rules = config('kkfarm_pages');
        $best = null;
        $score = -1;
        foreach ($rules as $r) {
            if ($r[0] !== $path) {
                continue;
            }
            $q = $r[1] ?? [];
            if (collect($q)->every(fn ($v, $k) => ($query[$k] ?? null) === $v) && count($q) > $score) {
                [$best, $score] = [$r, count($q)];
            }
        }
        if ($best || $path === '/') {
            return $best;
        }
        // a page under a listed path (a goat's page under /goats) follows that path's base rule
        $parts = explode('/', trim($path, '/'));
        while (count($parts) > 1) {
            array_pop($parts);
            $parent = '/'.implode('/', $parts);
            foreach ($rules as $r) {
                if ($r[0] === $parent && empty($r[1])) {
                    return $r;
                }
            }
        }

        return null;
    }

    /** May this person open the page? A per-person grant of the page's module (Users → the person) also opens it. */
    public static function allows(User $u, string $path, array $query = []): bool
    {
        $rule = self::rule($path, $query);
        if (! $rule) {
            return true; // not a listed page: the module check on its route decides
        }
        if (in_array($u->role, $rule[2], true)) {
            return true;
        }

        return self::grantedByOverride($u, $path);
    }

    /** A module ticked for this person on their own page opens that module's pages for them (RBAC §11). */
    private static function grantedByOverride(User $u, string $path): bool
    {
        $granted = collect($u->module_access ?? [])->filter()->keys();
        if ($granted->isEmpty()) {
            return false;
        }
        $owner = self::MODULE_OF[strtok(trim($path, '/'), '/') ?: ''] ?? null;

        return $owner !== null && $granted->contains($owner);
    }

    /** First path segment => module, for per-person grants. */
    private const MODULE_OF = [
        'goats' => 'goats', 'intake' => 'goats', 'movements' => 'goats', 'scan' => 'scan', 'worker' => 'scan', 'breeding' => 'breeding', 'genetics' => 'breeding', 'repro-loss' => 'breeding',
        'health' => 'health', 'disease' => 'health', 'parasites' => 'health', 'feeding' => 'feeding', 'feed-cost' => 'feed_cost', 'sheds' => 'sheds', 'housing' => 'sheds',
        'growth' => 'weights', 'weights' => 'weights', 'tasks' => 'tasks', 'operations' => 'tasks', 'employees' => 'employees', 'hr' => 'employees', 'inventory' => 'inventory',
        'purchase' => 'purchase', 'sales' => 'sales', 'transport' => 'sales', 'value' => 'value', 'finance' => 'finance', 'maintenance' => 'maintenance', 'sensors' => 'sensors',
        'milking' => 'milking', 'dairy' => 'milking', 'fattening' => 'fattening', 'welfare' => 'welfare', 'environment' => 'environment', 'waste' => 'environment',
        'quality' => 'quality', 'fodder' => 'fodder', 'reports' => 'reports', 'dashboard' => 'dashboard',
    ];

    /** @return list<array{0: string, 1: ?array, 2: bool}> every rule with whether this person may open it (for the menus) */
    public static function forUser(User $u): array
    {
        return collect(config('kkfarm_pages'))->map(fn ($r) => [$r[0], $r[1], in_array($u->role, $r[2], true) || self::grantedByOverride($u, $r[0])])->all();
    }

    /** Where this person lands after signing in and when a page is closed to them (RBAC §6). */
    public static function home(User $u): string
    {
        return in_array($u->role, ['ceo', 'manager'], true) ? '/' : '/home';
    }
}
