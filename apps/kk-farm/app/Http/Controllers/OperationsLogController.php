<?php

namespace App\Http\Controllers;

use App\Support\OpsLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-307/D-309: the Operations Log. ?app=feeding shows that app's operations (each app's Reporting menu opens its own);
 * without app, every operation of every app the person can open (the Reports app). Health operations need the right
 * to see health history.
 */
class OperationsLogController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $app = $request->query('app');
        $app = is_string($app) && isset(OpsLog::APPS[$app]) ? $app : null;
        $opens = fn (string $a) => collect(OpsLog::APPS[$a][1])->contains(fn ($m) => $user->canModule($m));
        abort_if($app && ! $opens($app), 403);
        $ops = OpsLog::forApp($app, $user->passes('see_health_history'));
        if (! $app) { // all apps: only operations from apps this person can open
            $ops = array_filter($ops, fn ($o) => collect($o[1])->contains(fn ($a) => $opens($a)));
        }

        return Inertia::render('Reports/Operations', [
            'app' => $app ? OpsLog::APPS[$app][0] : null,
            'rows' => OpsLog::rows($ops)->map(function ($r) use ($user) { // D-311: Correct only for who may (the person who recorded it, or the right to correct)
                $gate = ['weight' => 'record_weights', 'height' => 'record_height', 'checkup' => 'health_checkup'][$r['op']] ?? null;
                $may = $r['fix'] && match (true) {
                    $r['op'] === 'register' => $user->passes('edit_goats'),
                    $gate !== null => ($user->passes($gate) || $user->passes('correct_health_records')) && \App\Support\Revisions::canCorrect($user, $r['by']),
                    default => \App\Support\Revisions::canCorrect($user, $r['by']), // D-313: who recorded it, or the right to correct records
                };

                return ['fix' => $may ? $r['fix'] : null] + $r;
            })->values(),
            'ops' => collect($ops)->map(fn ($o, $k) => ['key' => $k, 'app' => OpsLog::APPS[$app ?? $o[1][0]][0], 'label' => $o[0]])->values(), // D-310: inside an app, all are its own
        ]);
    }

    /** D-313: correct any operation's record from the log: only the fields in OpsLog::FIX; the old values stay as a revision. */
    public function correct(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        $op = (string) $request->input('op');
        $def = OpsLog::ops()[$op] ?? null;
        abort_unless($def && isset(OpsLog::FIX[$op]), 404);
        abort_unless(collect($def[1])->contains(fn ($a) => collect(OpsLog::APPS[$a][1])->contains(fn ($m) => $user->canModule($m))), 403);
        abort_if(in_array($op, OpsLog::HEALTH, true) && ! $user->passes('see_health_history'), 403);
        [$label, , $table, , $byCol, $goatCol] = $def;
        $row = \Illuminate\Support\Facades\DB::table($table)->where('id', (int) $request->input('id'))->first();
        abort_unless($row, 404);
        abort_unless(\App\Support\Revisions::canCorrect($user, $byCol ? ($row->{$byCol} ?? null) : null), 403, 'Only the person who recorded this, or the administrator, can correct it.');
        $cols = array_filter(OpsLog::FIX[$op], fn ($l, $c) => property_exists($row, $c), ARRAY_FILTER_USE_BOTH);
        $rules = ['reason' => ['nullable', 'string', 'max:300']];
        foreach ($cols as $c => $l) {
            $rules["values.$c"] = match (OpsLog::fieldType($c)) { 'number' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'date' => ['nullable', 'date'], default => ['nullable', 'string', 'max:2000'] };
        }
        $data = $request->validate($rules, [], collect($cols)->mapWithKeys(fn ($l, $c) => ["values.$c" => $l])->all());
        $changes = [];
        $update = [];
        foreach ($cols as $c => $l) {
            if (! array_key_exists($c, $data['values'] ?? [])) {
                continue;
            }
            $new = $data['values'][$c];
            $old = $row->{$c};
            $same = match (OpsLog::fieldType($c)) {
                'number' => ($old === null && $new === null) || ($old !== null && $new !== null && abs((float) $old - (float) $new) < 0.0001),
                'date' => substr((string) $old, 0, 10) === substr((string) $new, 0, 10),
                default => (string) ($old ?? '') === (string) ($new ?? ''),
            };
            if (! $same) {
                $changes[$l] = [OpsLog::fieldType($c) === 'date' ? substr((string) $old, 0, 10) : $old, $new];
                $update[$c] = $new;
            }
        }
        if (! $changes) {
            return back()->with('success', 'Nothing changed.');
        }
        $goat = $goatCol && ($row->{$goatCol} ?? null) ? \App\Models\Animal::find($row->{$goatCol}) : null;
        \Illuminate\Support\Facades\DB::transaction(function () use ($table, $row, $update, $op, $goat, $changes, $user, $data) {
            \Illuminate\Support\Facades\DB::table($table)->where('id', $row->id)->update($update + ['updated_at' => now()]);
            $org = \App\Support\Tenant::org();
            // records that follow from this one
            if ($op === 'mating' && isset($update['mated_on'])) {
                $on = \Illuminate\Support\Carbon::parse($update['mated_on']);
                \Illuminate\Support\Facades\DB::table('matings')->where('id', $row->id)->update(['expected_kidding_on' => $on->copy()->addDays((int) ($org->gestation_days ?? 150))->toDateString(),
                    'check_due_on' => $on->copy()->addDays((int) ($org->pregnancy_check_days ?? 30))->toDateString()]);
            }
            if ($op === 'kidding' && isset($update['kidded_on'])) {
                \App\Models\Animal::where('birth_kidding_id', $row->id)->update(['birth_date' => $update['kidded_on']]);
            }
            if ($op === 'exit' && isset($update['moved_on']) && $goat && $goat->status !== 'active') {
                $goat->update(['exit_date' => $update['moved_on']]);
            }
            \App\Support\Revisions::log($op, (int) $row->id, $goat, $changes, $user, $data['reason'] ?? null);
        });

        return back()->with('success', "$label corrected: ".implode(', ', array_keys($changes)).'. The old values stay in the history.');
    }
}
