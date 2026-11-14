<?php

namespace App\Support;

use App\Models\User;

/**
 * D-266 step 5c: levels. Each app has a level for each role: None, View, Contribute, Edit or Manage. A level is a way to tick many
 * things at once and to read them back: config/kkfarm_levels.php says which app each action belongs to and the level it needs;
 * "Contribute in Feeding" means the app is open and every action of Feeding that needs View or Contribute is held, and no other.
 * Nothing here is a new gate on the server: the server keeps enforcing apps, pages and actions exactly as before, so day one nothing
 * changes. The level shown for a role is read from what it holds; when the ticks match no level the cell reads "Custom".
 * Vet-locked actions and Settings are never part of a level.
 */
final class Levels
{
    public const ORDER = ['none' => 0, 'view' => 1, 'contribute' => 2, 'edit' => 3, 'manage' => 4];
    public const LABELS = ['none' => 'None', 'view' => 'View', 'contribute' => 'Contribute', 'edit' => 'Edit', 'manage' => 'Manage', 'custom' => 'Custom'];
    public const MEANING = [
        'none' => 'The app is closed.', 'view' => 'Opens the app and its pages; no action.', 'contribute' => 'Records what the person does themselves.',
        'edit' => 'Changes what others recorded.', 'manage' => 'Approves, deletes and configures.',
    ];

    /** @return array<string, string> app key => label, every app a level can be set for */
    public static function areas(): array
    {
        return collect(Access::MODULE_LABELS)->except('settings')->all();
    }

    /** @return array<string, string> gate => level, for one app */
    public static function gatesOf(string $area): array
    {
        return collect(config('kkfarm_levels'))->filter(fn ($g) => $g['area'] === $area)->map(fn ($g) => $g['level'])->all();
    }

    /** What a level ticks: whether the app is open and which of its actions are held. @return array{module: bool, gates: array<string, bool>} */
    public static function target(string $area, string $level): array
    {
        $n = self::ORDER[$level];

        return ['module' => $n >= 1, 'gates' => collect(self::gatesOf($area))->map(fn ($need) => $n >= self::ORDER[$need])->all()];
    }

    /** @return array{module: bool, gates: array<string, bool>} what the role holds now in this app */
    public static function held(?string $org, string $role, string $area): array
    {
        $roleGates = Access::roleGates($org);

        return ['module' => in_array($role, Access::roleModules($org)[$area] ?? [], true),
            'gates' => collect(self::gatesOf($area))->map(fn ($need, $g) => in_array($role, $roleGates[$g] ?? [], true))->all()];
    }

    /** @return array{level: string, differs: list<string>} the level the role's ticks equal in this app, or 'custom' with what differs from the nearest level */
    public static function current(?string $org, string $role, string $area): array
    {
        $held = self::held($org, $role, $area);
        if (! $held['module']) {
            return ['level' => 'none', 'differs' => []];
        }
        $best = null;
        foreach (['view', 'contribute', 'edit', 'manage'] as $l) {
            $diff = self::diff($held, self::target($area, $l));
            if ($diff === []) {
                return ['level' => $l, 'differs' => []];
            }
            if ($best === null || count($diff) < count($best[1])) {
                $best = [$l, $diff];
            }
        }

        return ['level' => 'custom', 'differs' => array_map(fn ($g) => Access::labels()[$g] ?? $g, $best[1])];
    }

    /** @return list<string> the actions whose state differs */
    private static function diff(array $held, array $target): array
    {
        return collect($target['gates'])->filter(fn ($want, $g) => ($held['gates'][$g] ?? false) !== $want)->keys()->values()->all();
    }

    /** The ticks that picking this level would change. @return list<array{kind: string, key: string, label: string, old: bool, new: bool}> */
    public static function plan(?string $org, string $role, string $area, string $level): array
    {
        $held = self::held($org, $role, $area);
        $target = self::target($area, $level);
        $out = [];
        if ($held['module'] !== $target['module']) {
            $out[] = ['kind' => 'role_module', 'key' => $area, 'label' => 'The '.(Access::MODULE_LABELS[$area] ?? $area).' app', 'old' => $held['module'], 'new' => $target['module']];
        }
        foreach ($target['gates'] as $g => $want) {
            if (($held['gates'][$g] ?? false) !== $want) {
                $out[] = ['kind' => 'role_gate', 'key' => $g, 'label' => Access::labels()[$g] ?? $g, 'old' => $held['gates'][$g] ?? false, 'new' => $want];
            }
        }

        return $out;
    }

    /** May this role and app be set from here? The Administrator and Settings are fixed. */
    public static function settable(string $role, string $area, string $level): bool
    {
        return $role !== 'ceo' && isset(config('kkfarm.roles')[$role]) && isset(self::areas()[$area]) && isset(self::ORDER[$level]);
    }

    /** Pick a level for a role in an app: ticks exactly its actions, writes one history batch, returns the number of ticks changed. */
    public static function apply(User $actor, string $role, string $area, string $level): int
    {
        $org = $actor->org_id;
        $plan = self::plan($org, $role, $area, $level);
        if ($plan === []) {
            return 0;
        }
        $batch = AccessChanges::batch();
        $note = 'Level: '.self::LABELS[$level].' in '.(Access::MODULE_LABELS[$area] ?? $area);
        foreach ($plan as $c) {
            AccessChanges::set($org, $c['kind'], $role, $c['key'], $c['new']);
            Tenant::flush();
            AccessChanges::record($batch, $actor, $c['kind'], $role, config("kkfarm.roles.$role"), $c['key'], $c['label'], $c['old'], $c['new'], $note);
        }

        return count($plan);
    }
}
