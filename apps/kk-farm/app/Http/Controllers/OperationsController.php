<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\ChecklistRun;
use App\Models\ChecklistTemplate;
use App\Models\Department;
use App\Models\Incident;
use App\Models\Pen;
use App\Models\ShiftHandover;
use App\Models\Task;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-180: Farm Operations: today's shift checklists (ticked by staff, verified by a supervisor), the incident register
 * (anyone reports; owner, manager or vet resolves; high and critical incidents raise a task) and shift handovers.
 */
class OperationsController extends Controller
{
    private function supervisor(Request $request): bool
    {
        return $request->user()->passes('manage_checklists');
    }

    public function index(Request $request): Response
    {
        $today = now()->toDateString();
        $runs = ChecklistRun::where('run_on', $today)->get()->keyBy('template_id');

        return Inertia::render('Operations', [
            'checklists' => ChecklistTemplate::where('active', true)->orderByRaw("case shift when 'morning' then 0 when 'afternoon' then 1 else 2 end")->get()->map(fn (ChecklistTemplate $t) => [
                'id' => $t->id, 'name' => $t->name, 'shift' => $t->shift, 'items' => $t->items,
                'run' => isset($runs[$t->id]) ? ['id' => $runs[$t->id]->id, 'done' => $runs[$t->id]->done, 'by' => $runs[$t->id]->completed_by, 'at' => $runs[$t->id]->completed_at?->format('g:i A'),
                    'verifiedBy' => $runs[$t->id]->verified_by, 'notes' => $runs[$t->id]->notes] : null,
            ]),
            'history' => ChecklistRun::with('template')->where('run_on', '>=', now()->subDays(7)->toDateString())->where('run_on', '<', $today)->orderByDesc('run_on')->get()->map(fn (ChecklistRun $r) => [
                'on' => $r->run_on->format('d/m'), 'name' => $r->template?->name, 'done' => count($r->done), 'total' => count($r->template?->items ?? []), 'by' => $r->completed_by, 'verifiedBy' => $r->verified_by,
            ]),
            'incidents' => Incident::with(['pen.shed', 'animal', 'qualityCase', 'sop'])->where('occurred_at', '>=', now()->subDays(90))->orderByRaw("case status when 'open' then 0 else 1 end")->orderByDesc('occurred_at')->get()->map(fn (Incident $i) => [
                'id' => $i->id, 'at' => $i->occurred_at->format('d/m g:i A'), 'kind' => $i->kind, 'severity' => $i->severity, 'pen' => $i->pen?->fullName(),
                'goat' => $i->animal ? ['id' => $i->animal_id, 'number' => $i->animal->farm_number] : null, 'description' => $i->description, 'by' => $i->reported_by,
                'status' => $i->status, 'resolution' => $i->resolution, 'resolvedBy' => $i->resolved_by, 'resolvedAt' => $i->resolved_at?->format('d/m g:i A'),
                'category' => $i->category, 'rootCause' => $i->root_cause, 'repeat' => $i->recurrence_of_id !== null,
                'case' => $i->qualityCase ? ['label' => $i->qualityCase->label(), 'status' => $i->qualityCase->status] : null,
                'sop' => $i->sop ? "{$i->sop->code} v{$i->sop_version}".($i->sop_version_after ? " → v{$i->sop_version_after}" : '') : null,
            ]),
            'incidentKpi' => \App\Support\IncidentCapa::kpis(),
            'sops' => \App\Models\Sop::where('active', true)->orderBy('code')->get(['id', 'code', 'title']),
            'handovers' => ShiftHandover::where('handed_on', '>=', now()->subDays(14)->toDateString())->orderByDesc('id')->get()->map(fn (ShiftHandover $h) => [
                'id' => $h->id, 'on' => $h->handed_on->format('d/m'), 'shift' => $h->shift, 'from' => $h->from_name, 'to' => $h->to_name, 'summary' => $h->summary, 'pending' => $h->pending,
            ]),
            'templates' => ChecklistTemplate::orderByDesc('active')->orderBy('shift')->get(['id', 'name', 'shift', 'items', 'active']),
            'pens' => Pen::with('shed')->where('kind', 'pen')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName()])->sortBy('name')->values(),
            'goats' => Animal::active()->orderBy('farm_number')->get(['id', 'farm_number']),
            'options' => ['shifts' => ChecklistTemplate::SHIFTS, 'kinds' => Incident::KINDS, 'severity' => Incident::SEVERITY, 'categories' => Incident::CATEGORIES],
            'canSupervise' => $this->supervisor($request), 'canResolve' => $request->user()->passes('resolve_incidents'),
        ]);
    }

    public function tick(Request $request, ChecklistTemplate $template): RedirectResponse
    {
        $data = $request->validate(['done' => ['present', 'array'], 'done.*' => ['integer', 'min:0', 'max:'.(count($template->items) - 1)], 'notes' => ['nullable', 'string', 'max:500']]);
        $done = array_values(array_unique(array_map('intval', $data['done'])));
        $complete = count($done) === count($template->items);
        $run = ChecklistRun::firstOrNew(['template_id' => $template->id, 'run_on' => now()->toDateString()]);
        abort_if($run->verified_at !== null, 422, 'This checklist was already verified by a supervisor.');
        $run->fill(['done' => $done, 'notes' => $data['notes'] ?? $run->notes, 'completed_by' => $complete ? $request->user()->name : null, 'completed_at' => $complete ? now() : null])->save();
        if ($complete) {
            Farm::log($request->user(), "Completed the {$template->name}", '/operations');
        }

        return back()->with('success', $complete ? "{$template->name} completed." : 'Checklist saved.');
    }

    public function verify(Request $request, ChecklistRun $run): RedirectResponse
    {
        abort_unless($this->supervisor($request), 403, 'A supervisor verifies checklists.');
        abort_unless($run->completed_at !== null, 422, 'Only a completed checklist can be verified.');
        $run->update(['verified_by' => $request->user()->name, 'verified_at' => now()]);

        return back()->with('success', 'Checklist verified.');
    }

    public function incident(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'occurred_at' => ['required', 'date', 'before_or_equal:now'], 'kind' => ['required', Rule::in(array_keys(Incident::KINDS))],
            'severity' => ['required', Rule::in(array_keys(Incident::SEVERITY))], 'pen_id' => ['nullable', 'exists:pens,id'], 'animal_id' => ['nullable', 'exists:animals,id'],
            'description' => ['required', 'string', 'max:1000'], 'sop_id' => ['nullable', 'exists:sops,id'], 'containment' => ['nullable', Rule::in(['hold', 'isolate', 'recall'])],
        ]);
        $user = $request->user();
        abort_if(! empty($data['containment']) && ! ($user->passes('resolve_incidents')), 403, 'The owner, manager or vet places a hold.');
        $incident = DB::transaction(function () use ($data, $user) {
            $sop = ! empty($data['sop_id']) ? \App\Models\Sop::find($data['sop_id']) : null;
            $incident = Incident::create(collect($data)->except('containment')->all() + ['reported_by' => $user->name, 'category' => Incident::CATEGORY[$data['kind']] ?? 'operations', 'sop_version' => $sop?->version]);
            $incident->update(['recurrence_of_id' => \App\Support\IncidentCapa::repeatOf($incident)?->id]); // D-205
            if (\App\Support\IncidentCapa::mustEscalate($incident) || ! empty($data['containment'])) {
                \App\Support\IncidentCapa::escalate($incident, $user, $data['containment'] ?? null);
            }

            return $incident->fresh();
        });
        $label = Incident::KINDS[$data['kind']];
        if ($incident->animal) {
            Farm::event($incident->animal, 'health', "Incident: {$label}", $request->user()->name, $incident->occurred_at);
        }
        if (in_array($data['severity'], ['high', 'critical'], true)) {
            Task::create([
                'title' => mb_substr("Incident: {$label}".($incident->pen ? " – {$incident->pen->fullName()}" : ''), 0, 190), 'type' => 'other', 'priority' => $data['severity'],
                'status' => 'open', 'due_on' => now()->toDateString(), 'animal_id' => $incident->animal_id, 'pen_id' => $incident->pen_id,
                'department_id' => Department::where('name', 'Management')->value('id'), 'notes' => mb_substr($data['description'], 0, 500),
            ]);
        }
        Farm::log($request->user(), "Reported an incident: {$label}", '/operations?tab=incidents');

        return back()->with('success', $incident->quality_case_id ? 'Incident reported'.($incident->recurrence_of_id ? ' (a repeat)' : '').' and sent to a quality case for root cause and CAPA.' : 'Incident reported.');
    }

    public function resolve(Request $request, Incident $incident): RedirectResponse
    {
        abort_unless($request->user()->passes('resolve_incidents'), 403);
        abort_if($incident->status !== 'open', 422, $incident->status === 'escalated' ? 'This incident closes through its quality case (root cause, CAPA, independent verification).' : 'Already resolved.');
        $data = $request->validate(['resolution' => ['required', 'string', 'max:1000'], 'root_cause' => ['required', 'string', 'max:1000']], [], ['root_cause' => 'root cause']);
        $incident->update(['status' => 'resolved', 'resolution' => $data['resolution'], 'root_cause' => $data['root_cause'], 'resolved_by' => $request->user()->name, 'resolved_at' => now()]);

        return back()->with('success', 'Incident resolved.');
    }

    /** D-205: send any open incident to a quality case. */
    public function escalate(Request $request, Incident $incident): RedirectResponse
    {
        abort_unless($request->user()->passes('resolve_incidents'), 403);
        abort_unless($incident->status === 'open', 422, 'Only an open incident is escalated.');
        $data = $request->validate(['containment' => ['nullable', Rule::in(['hold', 'isolate', 'recall'])]]);
        $case = \App\Support\IncidentCapa::escalate($incident, $request->user(), $data['containment'] ?? null);

        return back()->with('success', "Sent to quality case {$case->label()}.");
    }

    public function handover(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shift' => ['required', Rule::in(array_keys(ChecklistTemplate::SHIFTS))], 'to_name' => ['nullable', 'string', 'max:80'],
            'summary' => ['required', 'string', 'max:2000'], 'pending' => ['nullable', 'string', 'max:1000'],
        ]);
        ShiftHandover::create($data + ['handed_on' => now()->toDateString(), 'from_name' => $request->user()->name]);

        return back()->with('success', 'Handover saved.');
    }

    public function template(Request $request, ?ChecklistTemplate $template = null): RedirectResponse
    {
        abort_unless($this->supervisor($request), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'shift' => ['required', Rule::in(array_keys(ChecklistTemplate::SHIFTS))],
            'items' => ['required', 'array', 'min:1', 'max:30'], 'items.*' => ['required', 'string', 'max:160'], 'active' => ['boolean'],
        ]);
        $template ? $template->update($data) : ChecklistTemplate::create($data);

        return back()->with('success', "{$data['name']} saved.");
    }
}
