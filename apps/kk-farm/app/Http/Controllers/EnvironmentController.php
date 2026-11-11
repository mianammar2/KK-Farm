<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Department;
use App\Models\EnvironmentIssue;
use App\Models\EnvironmentRound;
use App\Models\LedgerEntry;
use App\Models\MaintenanceJob;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\WaterCheck;
use App\Models\WaterPoint;
use App\Models\WaterTest;
use App\Support\Farm;
use App\Support\Tenant;
use App\Support\WaterIncident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-186: App 20 Water, Climate & Environment, following V3 flow 20 for each shed round:
 * scheduled readings / sensor alarm → check water points and tanks → water available and acceptable? No: emergency
 * supply and repair task (critical, BR-021), recheck. Yes → record climate and ventilation → heat / power / airflow
 * risk? Risk: shade, cooling and vet escalation until resolved (threshold is a farm setting, BR-022). Normal → check
 * manure, drainage, waste → compliant? No: contain, correct and verify. Yes → record utility cost + inspection.
 */
class EnvironmentController extends Controller
{
    private function canConfigure(Request $request): bool
    {
        return in_array($request->user()->role, ['ceo', 'manager', 'vet'], true);
    }

    private function threshold(): float
    {
        return (float) (Tenant::org()?->heat_stress_thi ?? 80);
    }

    public function index(Request $request): Response
    {
        $since = now()->subDays(30);
        $rounds30 = EnvironmentRound::where('round_at', '>=', $since)->get();
        $latest = EnvironmentRound::orderByDesc('round_at')->get()->unique('shed_id')->keyBy('shed_id');
        $points = WaterPoint::with(['shed', 'pen.shed'])->orderBy('shed_id')->orderBy('name')->get();

        return Inertia::render('Environment', [
            'sheds' => Shed::orderBy('name')->get()->map(fn (Shed $s) => [
                'id' => $s->id, 'name' => $s->name, 'typeName' => $s->typeName(),
                'points' => $points->where('shed_id', $s->id)->where('active', true)->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'kind' => $p->kind])->values(),
                'last' => isset($latest[$s->id]) ? ['at' => $latest[$s->id]->round_at->format('j M g:i A'), 'thi' => $latest[$s->id]->thi, 'temp' => $latest[$s->id]->temperature_c,
                    'risk' => $latest[$s->id]->climate_risk, 'water' => $latest[$s->id]->water_ok, 'waste' => $latest[$s->id]->waste_ok] : null,
                'openIssues' => EnvironmentIssue::where('shed_id', $s->id)->where('status', 'open')->count(),
            ]),
            'issues' => EnvironmentIssue::with(['shed', 'welfareCase', 'jobs.asset'])->where(fn ($q) => $q->where('status', '!=', 'verified')->orWhere('verified_at', '>=', now()->subDays(30)))
                ->orderByRaw("case status when 'open' then 0 when 'resolved' then 1 else 2 end")->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 else 2 end")->orderByDesc('id')->get()
                ->map(fn (EnvironmentIssue $i) => [
                    'id' => $i->id, 'shed' => $i->shed?->name, 'kind' => $i->kind, 'severity' => $i->severity, 'description' => $i->description, 'status' => $i->status,
                    'at' => $i->created_at->format('j M g:i A'), 'resolution' => $i->resolution, 'resolvedBy' => $i->resolved_by, 'verifiedBy' => $i->verified_by,
                    'water' => $i->kind === 'water' && $i->started_at ? [ // D-198: the incident timeline
                        'problem' => $i->problem, 'started' => $i->started_at->format('j M g:i A'), 'outage' => WaterIncident::outageMinutes($i),
                        'supply' => $i->supply_at ? ['source' => $i->supply_source, 'at' => $i->supply_at->format('j M g:i A'), 'by' => $i->supply_by, 'cost' => $i->supply_cost_minor,
                            'approvedBy' => $i->supply_approved_by, 'approvedAt' => $i->supply_approved_at?->format('j M g:i A')] : null,
                        'restored' => $i->restored_at?->format('j M g:i A'), 'exposed' => $i->goats_exposed, 'ill' => $i->goats_ill, 'outcome' => $i->health_outcome,
                        'checkedBy' => $i->health_checked_by, 'welfare' => $i->welfareCase ? ['label' => $i->welfareCase->label(), 'status' => $i->welfareCase->status] : null,
                        'jobs' => $i->jobs->map(fn ($j) => ['id' => $j->id, 'asset' => $j->asset?->name, 'status' => $j->status])->values(),
                        'cost' => WaterIncident::cost($i), 'blockers' => $i->status === 'resolved' ? WaterIncident::verifyBlockers($i) : [],
                    ] : null,
                ]),
            'rounds' => EnvironmentRound::with('shed')->where('round_at', '>=', $since)->orderByDesc('round_at')->limit(150)->get()->map(fn (EnvironmentRound $r) => [
                'id' => $r->id, 'at' => $r->round_at->format('j M g:i A'), 'shed' => $r->shed?->name, 'trigger' => $r->trigger, 'water' => $r->water_ok, 'temp' => $r->temperature_c,
                'rh' => $r->humidity_pct, 'thi' => $r->thi, 'risk' => $r->climate_risk, 'waste' => $r->waste_ok, 'cost' => $r->utility_cost_minor ? $r->utility_cost_minor / 100 : null,
                'utility' => $r->utility_kind, 'by' => $r->recorded_by,
            ]),
            'waterPoints' => $points->map(fn (WaterPoint $p) => ['id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'shedId' => $p->shed_id, 'shed' => $p->shed?->name, 'penId' => $p->pen_id,
                'assetId' => $p->asset_id, 'active' => $p->active]),
            'kpi' => [
                'interruptions' => EnvironmentIssue::where('kind', 'water')->where('created_at', '>=', $since)->count(),
                'heatExceptions' => $rounds30->where('climate_risk', true)->count(),
                'compliance' => $rounds30->count() ? round($rounds30->filter(fn ($r) => $r->water_ok && ! $r->climate_risk && $r->waste_ok)->count() / $rounds30->count() * 100) : null,
                'rounds' => $rounds30->count(), 'utility30' => (int) round($rounds30->sum('utility_cost_minor') / 100),
            ],
            'waterKpi' => WaterIncident::kpis(),
            'waterTests' => WaterTest::with('point.shed')->orderByDesc('sampled_on')->orderByDesc('id')->limit(60)->get()->map(fn (WaterTest $t) => [
                'id' => $t->id, 'point' => $t->point?->name, 'shed' => $t->point?->shed?->name, 'on' => $t->sampled_on->format('j M Y'), 'lab' => $t->lab, 'ph' => $t->ph, 'tds' => $t->tds_ppm,
                'coliform' => $t->coliform_cfu, 'nitrate' => $t->nitrate_mg_l, 'verdict' => $t->verdict, 'cost' => $t->cost_minor, 'by' => $t->recorded_by, 'notes' => $t->notes]),
            'isVet' => $request->user()->passes('clinical'),
            'threshold' => $this->threshold(),
            'pens' => Pen::with('shed')->where('kind', 'pen')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName(), 'shedId' => $p->shed_id])->values(),
            'assets' => Asset::where('status', '!=', 'retired')->orderBy('name')->get(['id', 'name', 'code']),
            'options' => ['kinds' => WaterPoint::KINDS, 'quality' => WaterCheck::QUALITY, 'issues' => EnvironmentIssue::KINDS, 'utilities' => EnvironmentRound::UTILITIES,
                'problems' => EnvironmentIssue::PROBLEMS, 'sources' => EnvironmentIssue::SOURCES],
            'canConfigure' => $this->canConfigure($request),
        ]);
    }

    public function round(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shed_id' => ['required', 'exists:sheds,id'], 'trigger' => ['required', Rule::in(['scheduled', 'sensor_alarm', 'report'])],
            'water' => ['present', 'array'], 'water.*.water_point_id' => ['required', 'exists:water_points,id'], 'water.*.available' => ['required', 'boolean'],
            'water.*.quality' => ['required', Rule::in(array_keys(WaterCheck::QUALITY))], 'water.*.tds_ppm' => ['nullable', 'integer', 'between:0,20000'],
            'water_ok' => ['nullable', 'boolean'],
            'temperature_c' => ['nullable', 'numeric', 'between:-10,60'], 'humidity_pct' => ['nullable', 'integer', 'between:0,100'],
            'ventilation_ok' => ['required', 'boolean'], 'power_ok' => ['required', 'boolean'], 'waste_ok' => ['required', 'boolean'], 'waste_notes' => ['nullable', 'required_if:waste_ok,false', 'string', 'max:500'],
            'utility_kind' => ['nullable', 'required_with:utility_cost', Rule::in(array_keys(EnvironmentRound::UTILITIES))], 'utility_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['waste_notes.required_if' => 'Describe the manure, drainage or waste problem.']);
        $shed = Shed::findOrFail($data['shed_id']);
        $user = $request->user();
        $issues = DB::transaction(function () use ($data, $shed, $user) {
            $checks = collect($data['water']);
            $waterOk = $checks->isNotEmpty() ? $checks->every(fn ($c) => $c['available'] && $c['quality'] === 'clean') : (bool) ($data['water_ok'] ?? true);
            $thi = isset($data['temperature_c'], $data['humidity_pct']) ? EnvironmentRound::thi((float) $data['temperature_c'], (int) $data['humidity_pct']) : null;
            $heat = $thi !== null && $thi >= $this->threshold();
            $risk = $heat || ! $data['power_ok'] || ! $data['ventilation_ok'];
            $cost = isset($data['utility_cost']) && $data['utility_cost'] > 0 ? (int) round($data['utility_cost'] * 100) : null;
            $ledger = $cost ? LedgerEntry::create([
                'direction' => 'out', 'category' => 'Utilities', 'amount' => $cost / 100, 'occurred_on' => now()->toDateString(),
                'description' => EnvironmentRound::UTILITIES[$data['utility_kind']]." · {$shed->name}", 'recorded_by' => $user->name, 'payment_status' => 'pending',
            ]) : null;
            $round = EnvironmentRound::create([
                'shed_id' => $shed->id, 'round_at' => now(), 'trigger' => $data['trigger'], 'water_ok' => $waterOk, 'temperature_c' => $data['temperature_c'] ?? null,
                'humidity_pct' => $data['humidity_pct'] ?? null, 'thi' => $thi, 'ventilation_ok' => $data['ventilation_ok'], 'power_ok' => $data['power_ok'], 'climate_risk' => $risk,
                'waste_ok' => $data['waste_ok'], 'waste_notes' => $data['waste_notes'] ?? null, 'utility_kind' => $cost ? $data['utility_kind'] : null, 'utility_cost_minor' => $cost,
                'ledger_entry_id' => $ledger?->id, 'notes' => $data['notes'] ?? null, 'recorded_by' => $user->name,
            ]);
            foreach ($checks as $c) {
                WaterCheck::create(['round_id' => $round->id] + $c);
            }
            $raised = [];
            if (! $waterOk) { // BR-021: no or unsafe drinking water is a critical owned alert; D-198: one water incident
                $bad = $checks->reject(fn ($c) => $c['available'] && $c['quality'] === 'clean');
                $names = WaterPoint::whereIn('id', $bad->pluck('water_point_id'))->pluck('name')->implode(', ');
                $problem = $bad->contains(fn ($c) => ! $c['available'] || $c['quality'] === 'empty') ? 'interruption' : 'inspection';
                $raised[] = WaterIncident::open($shed, $problem, $bad->pluck('water_point_id')->map(fn ($v) => (int) $v)->all(),
                    'Drinking water not available or not acceptable'.($names ? ": {$names}" : '').'. Give an emergency supply now and repair.', $user, $round);
            }
            if ($risk) {
                $what = collect([$heat ? "heat stress (THI {$thi}, limit {$this->threshold()})" : null, ! $data['power_ok'] ? 'power failure' : null, ! $data['ventilation_ok'] ? 'ventilation / airflow failure' : null])->filter()->implode(', ');
                $kind = $heat ? 'heat' : (! $data['power_ok'] ? 'power' : 'airflow');
                $severity = (! $data['power_ok'] || ($thi !== null && $thi >= $this->threshold() + 5)) ? 'critical' : 'high';
                $raised[] = $this->issue($round, $shed, $kind, $severity, ucfirst($what).'. Shade, cooling, water and airflow now; the vet checks the goats.', ['Management', 'Veterinary']);
            }
            if (! $data['waste_ok']) {
                $raised[] = $this->issue($round, $shed, 'waste', 'medium', "Manure, drainage or waste: {$data['waste_notes']}. Contain, correct and verify.", ['Management']);
            }

            return $raised;
        });
        Farm::log($user, "Environment round of {$shed->name}".($issues ? ' ('.count($issues).' issue'.(count($issues) > 1 ? 's' : '').')' : ''), '/environment');

        return back()->with('success', $issues ? count($issues).' issue(s) raised and assigned.' : "Round of {$shed->name} saved: all normal.");
    }

    /** @param list<string> $departments */
    private function issue(EnvironmentRound $round, Shed $shed, string $kind, string $severity, string $text, array $departments): EnvironmentIssue
    {
        $task = null;
        foreach ($departments as $dept) {
            $task = Task::create([
                'title' => mb_substr(EnvironmentIssue::KINDS[$kind].": {$shed->name}", 0, 190), 'type' => $kind === 'water' || $kind === 'waste' ? 'maintenance' : 'health',
                'priority' => $severity, 'status' => 'open', 'due_on' => now()->toDateString(), 'department_id' => Department::where('name', $dept)->value('id'), 'notes' => mb_substr($text, 0, 500),
            ]);
        }

        return EnvironmentIssue::create(['round_id' => $round->id, 'shed_id' => $shed->id, 'kind' => $kind, 'severity' => $severity, 'description' => $text, 'task_id' => $task?->id]);
    }

    public function resolve(Request $request, EnvironmentIssue $issue): RedirectResponse
    {
        abort_unless($issue->status === 'open', 422, 'This issue is already resolved.');
        $data = $request->validate(['resolution' => ['required', 'string', 'max:1000']]);
        if ($issue->kind === 'water') {
            WaterIncident::restore($issue); // D-198: resolving a water incident means normal supply is back
        }
        $issue->update(['status' => 'resolved', 'resolution' => $data['resolution'], 'resolved_by' => $request->user()->name, 'resolved_at' => now()]);

        return back()->with('success', 'Marked resolved. A supervisor verifies it.');
    }

    /** Verify (flow: recheck / resolved / verify) by owner, manager or vet; not by the person who resolved it (BR-027). */
    public function verify(Request $request, EnvironmentIssue $issue): RedirectResponse
    {
        abort_unless($this->canConfigure($request), 403);
        abort_unless($issue->status === 'resolved', 422, 'Only a resolved issue can be verified.');
        abort_if($issue->resolved_by === $request->user()->name && $request->user()->role !== 'ceo', 403, 'Someone other than the person who resolved it verifies.');
        if ($issue->kind === 'water' && ($why = WaterIncident::verifyBlockers($issue))) { // D-198: monitored resolution
            throw ValidationException::withMessages(['verify' => 'Cannot verify yet: '.implode(', ', $why).'.']);
        }
        $issue->update(['status' => 'verified', 'verified_by' => $request->user()->name, 'verified_at' => now()]);
        if ($issue->kind === 'water') {
            WaterIncident::closeWelfare($issue, $request->user());
        }
        Task::whereKey($issue->task_id)->whereIn('status', Task::OPEN)->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $request->user()->name]);

        return back()->with('success', 'Verified.');
    }

    public function waterPoint(Request $request, ?WaterPoint $point = null): RedirectResponse
    {
        abort_unless($this->canConfigure($request), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'kind' => ['required', Rule::in(array_keys(WaterPoint::KINDS))], 'shed_id' => ['required', 'exists:sheds,id'],
            'pen_id' => ['nullable', 'exists:pens,id'], 'asset_id' => ['nullable', 'exists:assets,id'], 'active' => ['boolean'],
        ]);
        $point ? $point->update($data) : WaterPoint::create($data);

        return back()->with('success', "{$data['name']} saved.");
    }

    public function settings(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['ceo', 'vet'], true), 403, 'The heat-stress limit is set by the owner or vet.');
        $data = $request->validate(['heat_stress_thi' => ['required', 'numeric', 'between:60,100']], [], ['heat_stress_thi' => 'heat-stress THI']);
        Organization::whereKey(Tenant::current())->update($data);

        return back()->with('success', "Heat-stress alert at THI {$data['heat_stress_thi']}.");
    }

    /** D-198: report a water interruption, shortage or contamination at any time, outside a round. */
    public function report(Request $request): RedirectResponse
    {
        $data = $request->validate(['shed_id' => ['required', 'exists:sheds,id'], 'problem' => ['required', Rule::in(['interruption', 'shortage', 'contamination'])],
            'point_ids' => ['array'], 'point_ids.*' => ['integer', 'exists:water_points,id'], 'description' => ['required', 'string', 'max:500']]);
        $shed = Shed::findOrFail($data['shed_id']);
        $points = WaterPoint::whereIn('id', $data['point_ids'] ?? [])->where('shed_id', $shed->id)->pluck('id')->all();
        $issue = WaterIncident::open($shed, $data['problem'], $points, $data['description'], $request->user());
        Farm::log($request->user(), "Reported water {$data['problem']} in {$shed->name}", '/environment?tab=issues');

        return back()->with('success', "Water incident open for {$shed->name}: {$issue->goats_exposed} goats affected. Management and the vet are assigned.");
    }

    /** D-198: a water lab test; an unsafe result opens (or widens) a contamination incident. The fee is posted once. */
    public function test(Request $request): RedirectResponse
    {
        $data = $request->validate(['water_point_id' => ['required', 'exists:water_points,id'], 'sampled_on' => ['required', 'date', 'before_or_equal:today'], 'lab' => ['nullable', 'string', 'max:120'],
            'ph' => ['nullable', 'numeric', 'between:0,14'], 'tds_ppm' => ['nullable', 'integer', 'between:0,50000'], 'coliform_cfu' => ['nullable', 'integer', 'min:0'],
            'nitrate_mg_l' => ['nullable', 'numeric', 'min:0'], 'verdict' => ['required', Rule::in(['safe', 'unsafe'])], 'notes' => ['nullable', 'string', 'max:500'], 'cost' => ['nullable', 'numeric', 'min:0']]);
        $user = $request->user();
        $issue = DB::transaction(function () use ($data, $user) {
            $point = WaterPoint::with('shed')->findOrFail($data['water_point_id']);
            $minor = ! empty($data['cost']) ? (int) round($data['cost'] * 100) : null;
            $ledger = $minor ? LedgerEntry::create(['direction' => 'out', 'category' => 'Water Testing', 'amount' => $minor / 100, 'occurred_on' => $data['sampled_on'], 'counterparty' => $data['lab'] ?? null,
                'description' => "Water test {$point->name}", 'recorded_by' => $user->name, 'payment_status' => 'pending']) : null;
            $test = WaterTest::create(collect($data)->except('cost')->all() + ['cost_minor' => $minor, 'ledger_entry_id' => $ledger?->id, 'recorded_by' => $user->name]);
            if ($data['verdict'] === 'unsafe' && $point->shed) {
                return WaterIncident::open($point->shed, 'contamination', [$point->id], "Lab test of {$point->name} unsafe".(isset($data['coliform_cfu']) ? " (coliform {$data['coliform_cfu']}/100 ml)" : '').'. Stop this water; give a safe supply.', $user, null, $test);
            }

            return null;
        });

        return back()->with('success', $issue ? 'Unsafe water: contamination incident opened.' : 'Water test saved.');
    }

    public function supply(Request $request, EnvironmentIssue $issue): RedirectResponse
    {
        $data = $request->validate(['source' => ['required', Rule::in(array_keys(EnvironmentIssue::SOURCES))], 'cost' => ['nullable', 'numeric', 'min:0']]);
        WaterIncident::supply($issue, $data['source'], isset($data['cost']) ? (float) $data['cost'] : null, $request->user());

        return back()->with('success', 'Emergency supply recorded. Someone else approves it as safe.');
    }

    public function approveSupply(Request $request, EnvironmentIssue $issue): RedirectResponse
    {
        WaterIncident::approveSupply($issue, $request->user());

        return back()->with('success', 'Emergency supply approved as safe.');
    }

    public function healthCheck(Request $request, EnvironmentIssue $issue): RedirectResponse
    {
        $data = $request->validate(['goats_ill' => ['required', 'integer', 'min:0'], 'outcome' => ['required', 'string', 'max:500']]);
        WaterIncident::healthCheck($issue, (int) $data['goats_ill'], $data['outcome'], $request->user());

        return back()->with('success', 'Health check recorded.');
    }
}
