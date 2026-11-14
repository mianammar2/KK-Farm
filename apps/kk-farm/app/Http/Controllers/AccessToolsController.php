<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Access;
use App\Support\AccessChanges;
use App\Support\AccessTrace;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/** D-266: the Administrator's tools around Access Rights: the change history with rollback, the "Why?" explainer and the health checks. */
class AccessToolsController extends Controller
{
    private function owner(Request $request): void
    {
        abort_unless($request->user()->role === 'ceo', 403, 'Only the Administrator uses the access tools.');
    }

    public function index(Request $request): Response
    {
        $this->owner($request);
        $tab = in_array($request->query('tab'), ['history', 'why', 'health'], true) ? $request->query('tab') : 'history';
        $org = $request->user()->org_id;
        $props = ['tab' => $tab, 'history' => null, 'why' => null, 'health' => null];
        if ($tab === 'history') {
            $props['history'] = AccessChanges::batches();
        } elseif ($tab === 'why') {
            $people = User::where('org_id', $org)->orderBy('name')->get(['id', 'name', 'role'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => config("kkfarm.roles.{$u->role}", $u->role)]);
            $trace = null;
            $who = User::where('org_id', $org)->find((int) $request->query('user'));
            [$kind, $key] = array_pad(explode('|', (string) $request->query('item'), 2), 2, '');
            if ($who && in_array($kind, ['module', 'gate', 'page'], true) && $key !== '') {
                $trace = AccessTrace::explain($who, $kind, $key) + ['person' => $who->name, 'role' => config("kkfarm.roles.{$who->role}", $who->role)];
            }
            $props['why'] = ['people' => $people, 'items' => collect(AccessTrace::items())->map(fn ($i) => ['value' => $i['kind'].'|'.$i['key'], 'label' => $i['label']])->values(), 'trace' => $trace,
                'user' => (string) $request->query('user', ''), 'item' => (string) $request->query('item', '')];
        } else {
            $props['health'] = $this->health($org);
        }

        return Inertia::render('Settings/AccessTools', $props);
    }

    public function rollback(Request $request, string $batch): RedirectResponse
    {
        $this->owner($request);
        abort_unless(preg_match('/^[0-9a-f-]{36}$/', $batch), 404);
        $n = AccessChanges::rollback($batch, $request->user());
        abort_if($n === null, 404);
        Farm::log($request->user(), "Rolled back an access-rights change ($n ".($n === 1 ? 'setting' : 'settings').')', '/settings/access/tools?tab=history', 'security');

        return back()->with('success', "Undone: $n ".($n === 1 ? 'setting' : 'settings').' put back.');
    }

    /** @return list<array{label: string, status: string, detail: string, items: list<string>}> */
    private function health(?string $org): array
    {
        $users = User::where('org_id', $org)->get();
        $roles = array_keys(config('kkfarm.roles'));
        $out = [];
        $add = function (string $label, string $status, string $detail, array $items = []) use (&$out) {
            $out[] = ['label' => $label, 'status' => $status, 'detail' => $detail, 'items' => array_slice($items, 0, 25)];
        };

        $admins = $users->where('role', 'ceo')->where('active', true)->count();
        $add('Active Administrators', $admins >= 2 ? 'ok' : 'warn', $admins === 0 ? 'No active Administrator.' : ($admins === 1 ? 'Only one: if that account is lost, nobody can change access rights.' : "$admins active Administrators."));

        $unknown = $users->reject(fn ($u) => in_array($u->role, $roles, true));
        $add('People with no valid role', $unknown->isEmpty() ? 'ok' : 'warn', $unknown->isEmpty() ? 'Everyone has one of the seven roles.' : $unknown->count().' with a role that does not exist.', $unknown->map(fn ($u) => "{$u->name} ({$u->role})")->all());

        $never = $users->where('active', true)->whereNull('last_login_at');
        $add('Accounts that never signed in', $never->isEmpty() ? 'ok' : 'warn', $never->isEmpty() ? 'Everyone active has signed in.' : $never->count().' active accounts have never signed in.', $never->map(fn ($u) => "{$u->name} · ".config("kkfarm.roles.{$u->role}", $u->role))->values()->all());

        $locked = $users->filter(fn ($u) => collect($u->gate_access ?? [])->only(Access::PROTECTED_GATES)->filter()->isNotEmpty() && $u->role !== 'vet');
        $add('People holding a vet-locked right the vet role does not give', $locked->isEmpty() ? 'ok' : 'info', $locked->isEmpty() ? 'None.' : 'Given by the Administrator to one person each.', $locked->map(fn ($u) => "{$u->name} · ".collect($u->gate_access)->only(Access::PROTECTED_GATES)->filter()->keys()->map(fn ($g) => Access::labels()[$g] ?? $g)->implode(', '))->values()->all());

        $stale = DB::table('user_page_access')->join('users', 'users.id', '=', 'user_page_access.user_id')->where('user_page_access.updated_at', '<', now()->subDays(90))
            ->get(['users.name', 'user_page_access.page_key', 'user_page_access.allowed', 'user_page_access.updated_at']);
        $add('Page ticks on a person older than 90 days', $stale->isEmpty() ? 'ok' : 'info', $stale->isEmpty() ? 'None. (Ticks for apps and actions carry no date; the change history shows when they were made.)' : 'Review whether they are still wanted.', $stale->map(fn ($r) => "{$r->name} · ".(\App\Support\Pages::registry()[$r->page_key]['label'] ?? $r->page_key).' · '.($r->allowed ? 'given' : 'refused'))->all());

        $own = $users->filter(fn ($u) => ! empty($u->module_access) || ! empty($u->gate_access));
        $add('People with their own ticks for apps or actions', $own->isEmpty() ? 'ok' : 'info', $own->isEmpty() ? 'Everyone follows their role.' : $own->count().' people differ from their role.', $own->map(fn ($u) => "{$u->name} · ".(count($u->module_access ?? []) + count($u->gate_access ?? [])).' ticks')->values()->all());

        $mods = Access::roleModules($org);
        $noRole = collect($mods)->filter(fn ($list, $m) => $list === [] && $m !== 'fodder')->keys()->map(fn ($m) => Access::MODULE_LABELS[$m] ?? $m)->values()->all();
        $add('Apps no role can open', $noRole ? 'warn' : 'ok', $noRole ? 'Nobody but a person with their own tick can open these.' : 'Every app is open to at least one role.', $noRole);

        $empty = collect($roles)->filter(fn ($r) => collect($mods)->filter(fn ($list) => in_array($r, $list, true))->isEmpty())->map(fn ($r) => config("kkfarm.roles.$r"))->values()->all();
        $add('Roles that can open nothing', $empty ? 'warn' : 'ok', $empty ? 'These roles sign in to an empty All Apps page.' : 'Every role opens something.', $empty);

        $rows = config('kkfarm_actions');
        $writes = collect(Route::getRoutes())->filter(fn ($r) => in_array('auth', $r->gatherMiddleware(), true))->flatMap(fn ($r) => collect(array_diff($r->methods(), ['GET', 'HEAD']))->map(fn ($m) => "$m /".ltrim($r->uri(), '/')));
        $missing = $writes->reject(fn ($k) => isset($rows[$k]))->values()->all();
        $add('Write routes with no rule', $missing ? 'warn' : 'ok', $missing ? 'These answer 403 to everyone until a rule is declared.' : 'All '.$writes->count().' write routes declare a rule (deny by default).', $missing);

        $hard = collect($rows)->filter(fn ($r) => isset($r['inline']) && ! isset($r['gate']))->keys()->all();
        $add('Actions the controller checks after the door', 'info', $hard ? count($hard).' routes let the people of the app in and the action then checks a right of its own. They follow the Access Rights screen; the door itself only knows the app.' : 'None.', $hard);

        return $out;
    }
}
