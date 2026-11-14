<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * D-266: the history of access-rights changes and the way back. Every save on Settings → Access Rights by Role and on a
 * person's Access rights page writes one row per changed app, action or page (old → new) under one batch id; a rollback
 * applies the old values of a batch and writes its own rows, so the history only ever grows.
 */
final class AccessChanges
{
    public const KINDS = ['role_module', 'role_gate', 'role_page', 'user_module', 'user_gate', 'user_page'];

    public static function batch(): string
    {
        return (string) Str::uuid();
    }

    /** @param bool|null $old @param bool|null $new  null = follows the role (person ticks) */
    public static function record(string $batch, User $actor, string $kind, string $subject, string $subjectLabel, string $key, string $itemLabel, ?bool $old, ?bool $new, ?string $note = null): void
    {
        if ($old === $new) {
            return;
        }
        DB::table('access_changes')->insert([
            'org_id' => $actor->org_id, 'batch' => $batch, 'actor' => $actor->name, 'kind' => $kind, 'subject' => $subject, 'subject_label' => mb_substr($subjectLabel, 0, 120),
            'key' => $key, 'item_label' => mb_substr($itemLabel, 0, 160), 'old_value' => $old === null ? null : ($old ? '1' : '0'), 'new_value' => $new === null ? null : ($new ? '1' : '0'),
            'note' => $note, 'created_at' => now(),
        ]);
    }

    /** @return list<array{batch: string, actor: string, at: string, count: int, rollback_of: ?string, changes: list<array>}> newest first */
    public static function batches(int $limit = 40): array
    {
        $ids = DB::table('access_changes')->select('batch', DB::raw('max(id) as last'))->groupBy('batch')->orderByDesc('last')->limit($limit)->pluck('batch');
        $rows = DB::table('access_changes')->whereIn('batch', $ids)->orderBy('id')->get()->groupBy('batch');
        $tz = Tenant::org()?->timezone ?: 'Asia/Karachi';

        return $ids->map(function ($b) use ($rows, $tz) {
            $r = $rows[$b];
            $first = $r->first();

            return ['batch' => $b, 'actor' => $first->actor, 'at' => \Illuminate\Support\Carbon::parse($first->created_at)->setTimezone($tz)->format('d/m/Y g:i A'), 'count' => $r->count(), 'rollback_of' => $first->note && str_starts_with($first->note, 'Rollback of ') ? substr($first->note, 12) : null,
                'changes' => $r->map(fn ($c) => ['kind' => $c->kind, 'subject' => $c->subject_label, 'item' => $c->item_label, 'old' => $c->old_value, 'new' => $c->new_value])->values()->all()];
        })->values()->all();
    }

    /** Put back what a batch changed. Returns the number of rows undone, or null when the batch does not exist. */
    public static function rollback(string $batch, User $actor): ?int
    {
        $rows = DB::table('access_changes')->where('batch', $batch)->orderByDesc('id')->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $new = self::batch();
        foreach ($rows as $c) {
            $before = self::current($actor->org_id, $c->kind, $c->subject, $c->key);
            self::apply($actor->org_id, $c->kind, $c->subject, $c->key, $c->old_value === null ? null : $c->old_value === '1');
            $after = self::current($actor->org_id, $c->kind, $c->subject, $c->key);
            self::record($new, $actor, $c->kind, $c->subject, $c->subject_label, $c->key, $c->item_label, $before, $after, 'Rollback of '.$batch);
        }
        Tenant::flush();

        return $rows->count();
    }

    /** The value a row stands at now, as the change history records it. */
    private static function current(?string $org, string $kind, string $subject, string $key): ?bool
    {
        Tenant::flush();
        $role = $subject;
        switch ($kind) {
            case 'role_module': return in_array($role, Access::roleModules($org)[$key] ?? [], true);
            case 'role_gate': return in_array($role, Access::roleGates($org)[$key] ?? [], true);
            case 'role_page': $r = Pages::registry()[$key] ?? null;

                return $r ? Pages::roleAllows($org, $role, $r) : null;
            case 'user_module': return User::find((int) $subject)?->module_access[$key] ?? null;
            case 'user_gate': return User::find((int) $subject)?->gate_access[$key] ?? null;
            default: return DB::table('user_page_access')->where('user_id', (int) $subject)->where('page_key', $key)->value('allowed') === null ? null : (bool) DB::table('user_page_access')->where('user_id', (int) $subject)->where('page_key', $key)->value('allowed');
        }
    }

    private static function apply(?string $org, string $kind, string $subject, string $key, ?bool $value): void
    {
        switch ($kind) {
            case 'role_module':
            case 'role_gate':
                if ($kind === 'role_gate' && in_array($key, Access::PROTECTED_GATES, true)) {
                    return; // vet-locked: the role screen never changes them
                }
                if ($kind === 'role_module' && $key === 'settings') {
                    return; // the Administrator's alone
                }
                $col = $kind === 'role_module' ? 'role_modules' : 'role_gates';
                $map = $kind === 'role_module' ? Access::roleModules($org) : Access::roleGates($org);
                $has = collect($map[$key] ?? []);
                $map[$key] = ($value ? $has->push($subject)->unique() : $has->reject(fn ($r) => $r === $subject))->values()->all();
                $save = $kind === 'role_gate' ? array_diff_key($map, array_flip(Access::PROTECTED_GATES)) : $map;
                Organization::whereKey($org)->update([$col => json_encode($save)]);
                break;
            case 'role_page':
                $r = Pages::registry()[$key] ?? null;
                if (! $r || $r['lock'] !== null || $value === null) {
                    return;
                }
                $value === Pages::defaultFor($subject, $r)
                    ? DB::table('role_page_access')->where('role', $subject)->where('page_key', $key)->delete()
                    : DB::table('role_page_access')->updateOrInsert(['org_id' => $org, 'role' => $subject, 'page_key' => $key], ['allowed' => $value, 'updated_at' => now(), 'created_at' => now()]);
                break;
            case 'user_module':
            case 'user_gate':
                $u = User::where('org_id', $org)->find((int) $subject);
                if (! $u || ($kind === 'user_module' && $key === 'settings')) {
                    return;
                }
                $col = $kind === 'user_module' ? 'module_access' : 'gate_access';
                $ticks = $u->{$col} ?? [];
                $value === null ? ($ticks = array_diff_key($ticks, [$key => 1])) : ($ticks[$key] = $value);
                $u->forceFill([$col => $ticks ?: null])->save();
                break;
            default:
                $u = User::where('org_id', $org)->find((int) $subject);
                if (! $u) {
                    return;
                }
                $value === null
                    ? DB::table('user_page_access')->where('user_id', $u->id)->where('page_key', $key)->delete()
                    : DB::table('user_page_access')->updateOrInsert(['org_id' => $org, 'user_id' => $u->id, 'page_key' => $key], ['allowed' => $value, 'updated_at' => now(), 'created_at' => now()]);
        }
    }
}
