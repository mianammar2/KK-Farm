<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Support\Access;
use App\Support\Farm;
use App\Support\Pages;
use Illuminate\Support\Facades\DB;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-215: Odoo-style access rights. Settings → Access Rights by Role is the master (what each role opens and may do);
 * Settings → Users → a person is the individual page (tick or untick an app or action for that person; untouched
 * boxes follow the role). Both read and write the same rules (App\Support\Access), so they always agree.
 */
class AccessController extends Controller
{
    private function owner(Request $request): void
    {
        abort_unless($request->user()->role === 'ceo', 403, 'Only the owner changes access rights.');
    }

    public function roles(Request $request): Response
    {
        $org = $request->user()->org_id;

        return Inertia::render('Settings/Access', [
            'modules' => Access::roleModules($org), 'gates' => Access::roleGates($org),
            'moduleLabels' => Access::MODULE_LABELS, 'gateLabels' => Access::labels(), 'protected' => Access::PROTECTED_GATES,
            'overrides' => User::where('org_id', $org)->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'count' => count($u->module_access ?? []) + count($u->gate_access ?? []) + count(Pages::userTicks($u))])
                ->filter(fn ($o) => $o['count'] > 0)->values(),
            'canEdit' => $request->user()->role === 'ceo',
            'pages' => $this->pageRows($org), // D-243
        ]);
    }

    /** D-243: every page the administrator can see in the editor, with each role's default and current tick. */
    private function pageRows(?string $org): array
    {
        $roles = array_keys(config('kkfarm.roles'));

        return collect(Pages::registry())->reject(fn ($r) => in_array($r['lock'], ['opening', 'always'], true))->map(fn ($r) => [
            'key' => $r['key'], 'label' => $r['label'], 'module' => $r['module'], 'group' => $r['group'], 'lock' => $r['lock'], 'menus' => $r['menus'],
            'href' => $r['path'].($r['query'] ? '?'.http_build_query($r['query']) : ''),
            'default' => collect($roles)->mapWithKeys(fn ($role) => [$role => $r['lock'] === 'admin' ? $role === 'ceo' : Pages::defaultFor($role, $r)]),
            'value' => collect($roles)->mapWithKeys(fn ($role) => [$role => $r['lock'] === 'admin' ? $role === 'ceo' : Pages::roleAllows($org, $role, $r)]),
        ])->values()->all();
    }

    /** D-243: save the page ticks per role. Only differences from the default are stored; every change is logged. */
    private function savePages(Request $request, array $pages): int
    {
        $org = $request->user()->org_id;
        $roles = array_keys(config('kkfarm.roles'));
        $changed = 0;
        foreach (Pages::registry() as $key => $r) {
            if ($r['lock'] !== null || ! isset($pages[$key]) || ! is_array($pages[$key])) {
                continue; // locked pages never change from this screen
            }
            foreach ($roles as $role) {
                if (! array_key_exists($role, $pages[$key])) {
                    continue;
                }
                $new = (bool) $pages[$key][$role];
                $old = Pages::roleAllows($org, $role, $r);
                if ($new === $old) {
                    continue;
                }
                $new === Pages::defaultFor($role, $r)
                    ? DB::table('role_page_access')->where('role', $role)->where('page_key', $key)->delete()
                    : DB::table('role_page_access')->updateOrInsert(['org_id' => $org, 'role' => $role, 'page_key' => $key], ['allowed' => $new, 'updated_at' => now(), 'created_at' => now()]);
                Farm::log($request->user(), 'Page access: '.config("kkfarm.roles.$role")." · {$r['label']}: ".($old ? 'open' : 'closed').' → '.($new ? 'open' : 'closed'), '/settings/access', 'security');
                $changed++;
            }
        }
        Tenant::flush();

        return $changed;
    }

    public function saveRoles(Request $request): RedirectResponse
    {
        $this->owner($request);
        $roles = array_keys(config('kkfarm.roles'));
        $data = $request->validate(['modules' => ['present', 'array'], 'gates' => ['present', 'array'], 'pages' => ['sometimes', 'array']]);
        $clean = function (array $map, array $keys) use ($roles) {
            $out = [];
            foreach ($keys as $k) {
                $out[$k] = array_values(array_intersect($roles, (array) ($map[$k] ?? [])));
            }

            return $out;
        };
        $modules = $clean($data['modules'], array_keys(config('kkfarm.modules')));
        $modules['settings'] = array_values(array_unique([...$modules['settings'], 'ceo']));
        $gates = array_diff_key($clean($data['gates'], array_keys(config('kkfarm.gates'))), array_flip(Access::PROTECTED_GATES));
        Organization::whereKey($request->user()->org_id)->update(['role_modules' => json_encode($modules), 'role_gates' => json_encode($gates)]);
        Tenant::flush();
        $this->savePages($request, $data['pages'] ?? []);
        Farm::log($request->user(), 'Changed access rights by role', '/settings/access');

        return back()->with('success', 'Access rights saved. Users without their own ticks follow these at once.');
    }

    public function user(Request $request, int $id): Response
    {
        $u = User::where('org_id', $request->user()->org_id)->findOrFail($id);
        $org = $u->org_id;
        $roleModules = Access::roleModules($org);
        $roleGates = Access::roleGates($org);

        return Inertia::render('Settings/UserAccess', [
            'user' => ['id' => $u->id, 'name' => $u->name, 'login' => $u->email, 'role' => $u->role, 'active' => $u->active, 'department' => $u->department_id, 'me' => $u->is($request->user()),
                'lastLogin' => $u->last_login_at?->format('d/m/Y H:i')],
            'modules' => collect(Access::MODULE_LABELS)->map(fn ($label, $m) => ['key' => $m, 'label' => $label, 'role' => in_array($u->role, $roleModules[$m] ?? [], true),
                'own' => $u->module_access[$m] ?? null, 'effective' => $u->canModule($m), 'locked' => $m === 'settings' && $u->role === 'ceo'])->values(),
            'gates' => collect(Access::labels())->map(fn ($label, $g) => ['key' => $g, 'label' => $label, 'role' => in_array($u->role, $roleGates[$g] ?? [], true),
                'own' => $u->gate_access[$g] ?? null, 'effective' => $u->passes($g), 'locked' => false, 'vetLocked' => in_array($g, Access::PROTECTED_GATES, true)])->values(), // D-241: the administrator may grant a vet-locked action to one person
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'canEdit' => $request->user()->role === 'ceo',
            // D-243: every page, pre-filled from the role; the person's own ticks on top
            'pages' => collect(Pages::registry())->reject(fn ($r) => in_array($r['lock'], ['opening', 'always'], true))->map(fn ($r) => [
                'key' => $r['key'], 'label' => $r['label'], 'module' => $r['module'], 'group' => $r['group'], 'lock' => $r['lock'], 'menus' => $r['menus'],
                'role' => $r['lock'] === 'admin' ? $u->role === 'ceo' : Pages::roleAllows($org, $u->role, $r), 'own' => Pages::userTicks($u)[$r['key']] ?? null,
                'effective' => Pages::allowsKey($u, $r['key']), 'moduleOn' => $u->canModule($r['module']), 'editable' => Pages::userEditable($r),
            ])->values(),
        ]);
    }

    /** One person's ticks: true gives, false refuses, null goes back to the role. */
    public function saveUser(Request $request, int $id): RedirectResponse
    {
        $this->owner($request);
        $u = User::where('org_id', $request->user()->org_id)->findOrFail($id);
        $data = $request->validate(['modules' => ['array'], 'modules.*' => ['nullable', 'boolean'], 'gates' => ['array'], 'gates.*' => ['nullable', 'boolean'], 'pages' => ['sometimes', 'array'], 'pages.*' => ['nullable', 'boolean']]);
        $keep = fn (array $in, array $allowed) => array_map('boolval', array_filter(array_intersect_key($in, array_flip($allowed)), fn ($v) => $v !== null));
        $modules = $keep($data['modules'] ?? [], array_keys(config('kkfarm.modules')));
        if ($u->role === 'ceo') {
            unset($modules['settings']);
        }
        $gates = $keep($data['gates'] ?? [], array_keys(config('kkfarm.gates'))); // D-241: vet-locked actions too, per person, by the administrator only
        $u->forceFill(['module_access' => $modules ?: null, 'gate_access' => $gates ?: null])->save();
        // D-243: the person's own page ticks (null = follow the role); locked Administrator pages never change
        foreach ($data['pages'] ?? [] as $key => $value) {
            $r = Pages::registry()[$key] ?? null;
            if (! $r || ! Pages::userEditable($r)) {
                continue;
            }
            $old = Pages::userTicks($u)[$key] ?? null;
            $new = $value === null ? null : (bool) $value;
            if ($old === $new) {
                continue;
            }
            $new === null
                ? DB::table('user_page_access')->where('user_id', $u->id)->where('page_key', $key)->delete()
                : DB::table('user_page_access')->updateOrInsert(['org_id' => $u->org_id, 'user_id' => $u->id, 'page_key' => $key], ['allowed' => $new, 'updated_at' => now(), 'created_at' => now()]);
            Farm::log($request->user(), "Page access: {$u->name} · {$r['label']}: ".($old === null ? 'from role' : ($old ? 'given' : 'refused')).' → '.($new === null ? 'from role' : ($new ? 'given' : 'refused')), "/settings/users/{$u->id}", 'security');
        }
        Tenant::flush();
        Farm::log($request->user(), "Changed access rights of {$u->name}", "/settings/users/{$u->id}");

        return back()->with('success', "Access rights of {$u->name} saved.");
    }
}
