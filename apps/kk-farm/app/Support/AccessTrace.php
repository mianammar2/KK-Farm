<?php

namespace App\Support;

use App\Models\User;

/**
 * D-266 ("Why?"): the decision trace for one person and one app, action or page: each step in the order it is applied, what it said,
 * and which step settled the answer. The final answer is always read from the real resolver (Access, Pages), never recomputed here,
 * so the explanation can not drift from what the server does.
 */
final class AccessTrace
{
    /** @return array{label: string, decision: bool, steps: list<array{text: string, says: ?string, decides: bool}>} */
    public static function explain(User $u, string $kind, string $key): array
    {
        return match ($kind) {
            'module' => self::module($u, $key),
            'gate' => self::gate($u, $key),
            default => self::page($u, $key),
        };
    }

    /** @return list<array{kind: string, key: string, label: string}> everything that can be asked about */
    public static function items(): array
    {
        return collect(Access::MODULE_LABELS)->map(fn ($l, $k) => ['kind' => 'module', 'key' => $k, 'label' => "App · $l"])
            ->concat(collect(Access::labels())->map(fn ($l, $k) => ['kind' => 'gate', 'key' => $k, 'label' => "Action · $l"]))
            ->concat(collect(Pages::registry())->reject(fn ($r) => in_array($r['lock'], ['opening', 'always'], true))->map(fn ($r, $k) => ['kind' => 'page', 'key' => $k, 'label' => "Page · {$r['label']} ({$r['module']})"]))
            ->values()->all();
    }

    private static function step(array &$steps, string $text, ?string $says, bool $decides = false): void
    {
        $steps[] = ['text' => $text, 'says' => $says, 'decides' => $decides];
    }

    private static function yn(?bool $v): ?string
    {
        return $v === null ? null : ($v ? 'allows' : 'refuses');
    }

    private static function module(User $u, string $m): array
    {
        $steps = [];
        $label = Access::MODULE_LABELS[$m] ?? $m;
        $final = Access::module($u, $m);
        if ($m === 'settings') {
            self::step($steps, "Settings is the Administrator's alone, however it is ticked.", self::yn($u->role === 'ceo'), true);

            return compact('label', 'steps') + ['decision' => $final];
        }
        $own = $u->module_access[$m] ?? null;
        self::step($steps, "This person's own tick for the app".($own === null ? ' (none: they follow their role)' : ''), self::yn($own), $own !== null);
        $roleOn = in_array($u->role, Access::roleModules($u->org_id)[$m] ?? [], true);
        $default = in_array($u->role, config("kkfarm.modules.$m", []), true);
        self::step($steps, config("kkfarm.roles.{$u->role}").' role setting'.($roleOn === $default ? ' (the built-in default)' : ' (changed by the Administrator from the default: '.($default ? 'open' : 'closed').')'), self::yn($roleOn), $own === null);
        if ($m === 'fodder' && $u->role !== 'ceo' && ! \App\Support\Fodder::enabled()) {
            self::step($steps, 'The optional Fodder app is switched off for the farm.', 'refuses', true);
        }

        return compact('label', 'steps') + ['decision' => $final];
    }

    private static function gate(User $u, string $g): array
    {
        $steps = [];
        $label = Access::labels()[$g] ?? $g;
        $final = Access::gate($u, $g);
        $own = $u->gate_access[$g] ?? null;
        $locked = in_array($g, Access::PROTECTED_GATES, true);
        if ($locked) {
            self::step($steps, 'Vet-locked: roles are fixed (the vet only). Only the Administrator can give it to one person.', null);
        }
        self::step($steps, "This person's own tick for the action".($own === null ? ' (none: they follow their role)' : ''), self::yn($own), $own !== null);
        $roleOn = $locked ? in_array($u->role, config("kkfarm.gates.$g", []), true) : in_array($u->role, Access::roleGates($u->org_id)[$g] ?? [], true);
        $default = in_array($u->role, config("kkfarm.gates.$g", []), true);
        self::step($steps, config("kkfarm.roles.{$u->role}").' role setting'.($roleOn === $default ? ' (the built-in default)' : ' (changed by the Administrator from the default: '.($default ? 'allowed' : 'not allowed').')'), self::yn($roleOn), $own === null);

        return compact('label', 'steps') + ['decision' => $final];
    }

    private static function page(User $u, string $key): array
    {
        $steps = [];
        $r = Pages::registry()[$key] ?? null;
        if (! $r) {
            self::step($steps, 'This page is not in the page registry: pages not listed are the Administrator\'s only.', self::yn($u->role === 'ceo'), true);

            return ['label' => $key, 'decision' => Pages::allowsKey($u, $key), 'steps' => $steps];
        }
        $final = Pages::allowsKey($u, $key);
        $label = $r['label'];
        if ($r['lock'] === 'admin') {
            self::step($steps, "Administrator-only page (locked: it can not be changed).", self::yn($u->role === 'ceo'), true);

            return compact('label', 'steps') + ['decision' => $final];
        }
        if ($r['lock'] === 'always') {
            self::step($steps, 'Open to everyone who can sign in (locked).', 'allows', true);

            return compact('label', 'steps') + ['decision' => $final];
        }
        if ($r['lock'] === 'opening') {
            self::step($steps, 'The address without a tab opens when any of its tabs opens for the person.', self::yn($final), true);

            return compact('label', 'steps') + ['decision' => $final];
        }
        $moduleOn = $u->canModule($r['module']);
        self::step($steps, 'The app this page belongs to ('.(Access::MODULE_LABELS[$r['module']] ?? $r['module']).') must be open to the person', self::yn($moduleOn), ! $moduleOn);
        $own = Pages::userTicks($u)[$key] ?? null;
        self::step($steps, "This person's own tick for the page".($own === null ? ' (none)' : ''), self::yn($own), $moduleOn && $own !== null);
        if ($r['lock'] === 'vet') {
            self::step($steps, 'Vet-locked page: the roles are fixed; the Administrator may give it to one person (the person tick above).', self::yn(in_array($u->role, $r['roles'], true)), $moduleOn && $own === null);
        } else {
            $saved = Pages::roleTicks($u->org_id)[$u->role][$key] ?? null;
            self::step($steps, config("kkfarm.roles.{$u->role}").' role tick for the page'.($saved === null ? ' (none: the default applies)' : ' (set by the Administrator)'), self::yn($saved), $moduleOn && $own === null && $saved !== null);
            self::step($steps, 'Built-in default for this role', self::yn(Pages::defaultFor($u->role, $r)), $moduleOn && $own === null && $saved === null);
        }

        return compact('label', 'steps') + ['decision' => $final];
    }
}
