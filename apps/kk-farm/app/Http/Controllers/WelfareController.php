<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Department;
use App\Models\HealthCase;
use App\Models\HealthRecord;
use App\Models\Pen;
use App\Models\Task;
use App\Models\WelfareAction;
use App\Models\WelfareCase;
use App\Models\WelfareCheck;
use App\Support\Farm;
use App\Support\Movements;
use App\Support\WelfareCare;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-185: App 19 Animal Welfare & Husbandry, following V3 flow 19:
 * daily welfare / mobility check → record BCS, behaviour, injury → issue or pain? No: mark normal, next inspection.
 * Yes: welfare case and triage → urgent veterinary referral? Yes: isolate / treat under vet plan (the vet's queue;
 * welfare never bypasses medical authorisation). No: hoof / handling / habitat action → follow-up: confirm welfare
 * improvement? Yes: close with evidence. No: escalate / repeat clinical review → reassess.
 * BR-023: a high or critical welfare concern creates an inspection task for the vet.
 */
class WelfareController extends Controller
{
    public function index(Request $request): Response
    {
        $cases = WelfareCase::with(['animal', 'pen.shed', 'actions', 'healthCase', 'recurrenceOf'])->where(fn ($q) => $q->where('status', '!=', 'closed')->orWhere('closed_at', '>=', now()->subDays(90)))
            ->orderByRaw("case status when 'escalated' then 0 when 'open' then 1 when 'vet_referral' then 2 when 'action' then 3 when 'follow_up' then 4 else 5 end")->orderByDesc('opened_on')->get();
        $from = now()->subDays(30)->toDateString();
        $checks30 = WelfareCheck::where('checked_on', '>=', $from)->get();
        $closed = WelfareCase::whereNotNull('closed_at')->where('closed_at', '>=', now()->subDays(90))->get();
        $latest = WelfareCheck::whereNotNull('animal_id')->selectRaw('animal_id, max(checked_on) as last_on, max(next_check_on) as next_on')->groupBy('animal_id')->get();

        $users = \App\Models\User::where('org_id', \App\Support\Tenant::current())->orderBy('name')->pluck('name', 'id');

        return Inertia::render('Welfare', [
            'cases' => $cases->map(fn (WelfareCase $c) => [
                'id' => $c->id, 'no' => $c->label(), 'goat' => $c->animal ? ['id' => $c->animal_id, 'number' => $c->animal->farm_number] : null, 'pen' => $c->pen?->fullName(),
                'opened' => $c->opened_on->format('d/m/Y'), 'issue' => $c->issue, 'severity' => $c->severity, 'triage' => $c->triage, 'status' => $c->status, 'by' => $c->opened_by,
                'referred' => $c->health_record_id !== null, 'closedBy' => $c->closed_by, 'closedAt' => $c->closed_at?->format('d/m/Y'), 'evidence' => $c->closure_evidence,
                'canClose' => $c->status === 'follow_up' || ($c->status === 'action' && $c->actions->where('kind', 'follow_up')->where('improved', true)->isNotEmpty()),
                'steps' => $c->actions->map(fn (WelfareAction $a) => ['kind' => $a->kind, 'notes' => $a->notes, 'improved' => $a->improved, 'by' => $a->done_by, 'on' => $a->done_on->format('d/m'),
                    'assignee' => $a->assigned_user_id ? ($users[$a->assigned_user_id] ?? null) : null, 'due' => $a->due_on?->format('d/m')])->values(),
                // D-199
                'healthCase' => $c->healthCase ? ['label' => $c->healthCase->label(), 'condition' => $c->healthCase->condition, 'status' => $c->healthCase->status, 'active' => in_array($c->healthCase->status, HealthCase::ACTIVE, true)] : null,
                'repeatOf' => $c->recurrenceOf?->label(), 'restricted' => WelfareCare::restricts($c), 'handlingClearedBy' => $c->handling_cleared_by,
                'held' => Task::where('welfare_hold_case_id', $c->id)->where('status', 'blocked')->count(),
            ]),
            'checks' => WelfareCheck::with(['pen.shed', 'animal'])->where('checked_on', '>=', now()->subDays(14)->toDateString())->orderByDesc('checked_on')->orderByDesc('id')->limit(200)->get()->map(fn (WelfareCheck $w) => [
                'id' => $w->id, 'on' => $w->checked_on->format('d/m'), 'goat' => $w->animal?->farm_number, 'pen' => $w->pen?->fullName(),
                'bcs' => $w->bcs, 'behaviour' => $w->behaviour, 'injury' => $w->injury, 'lameness' => $w->lameness, 'pain' => $w->pain, 'caseId' => $w->case_id, 'by' => $w->checked_by,
            ]),
            'due' => $latest->filter(fn ($l) => $l->next_on && $l->next_on <= now()->toDateString())->count(),
            'kpi' => [
                'checks' => $checks30->count(),
                'lamenessRate' => $checks30->count() ? round($checks30->where('lameness', '>=', 1)->count() / $checks30->count() * 100, 1) : null,
                'unresolved' => WelfareCase::where('status', '!=', 'closed')->count(),
                'closeDays' => $closed->count() ? round($closed->avg(fn ($c) => $c->opened_on->diffInDays($c->closed_at)), 1) : null,
            ] + WelfareCare::kpis(),
            'users' => $users->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values(),
            'goats' => Animal::active()->orderBy('farm_number')->get(['id', 'farm_number']),
            'pens' => Pen::with('shed')->where('kind', 'pen')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName()])->sortBy('name')->values(),
            'options' => ['issues' => WelfareCase::ISSUES, 'severity' => WelfareCase::SEVERITY, 'status' => WelfareCase::STATUS, 'behaviour' => WelfareCheck::BEHAVIOUR,
                'injury' => WelfareCheck::INJURY, 'lameness' => WelfareCheck::LAMENESS, 'actions' => WelfareAction::KINDS],
            'isVet' => $request->user()->passes('clinical'),
        ]);
    }

    /** Daily welfare / mobility check. An issue or pain opens a welfare case for triage. */
    public function check(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'animal_id' => ['nullable', 'required_without:pen_id', 'exists:animals,id'], 'pen_id' => ['nullable', 'exists:pens,id'],
            'checked_on' => ['required', 'date', 'before_or_equal:today'], 'bcs' => ['nullable', 'integer', 'between:1,5'],
            'behaviour' => ['required', Rule::in(array_keys(WelfareCheck::BEHAVIOUR))], 'injury' => ['required', Rule::in(array_keys(WelfareCheck::INJURY))],
            'lameness' => ['required', 'integer', 'between:0,3'], 'pain' => ['boolean'], 'notes' => ['nullable', 'string', 'max:500'],
            'next_check_on' => ['nullable', 'date', 'after:checked_on'],
        ], [], ['animal_id' => 'goat or PEN']);
        $issue = ! empty($data['pain']) || $data['injury'] !== 'none' || $data['lameness'] >= 2 || $data['behaviour'] !== 'normal' || (($data['bcs'] ?? 3) <= 1);
        $by = $request->user()->name;
        $case = DB::transaction(function () use ($data, $issue, $by) {
            $case = null;
            if ($issue) {
                $kind = match (true) {
                    $data['lameness'] >= 2 => 'lameness', $data['injury'] !== 'none' => 'injury', ($data['bcs'] ?? 3) <= 1 => 'body_condition',
                    $data['behaviour'] === 'aggressive' => 'aggression', default => 'behaviour',
                };
                $severity = match (true) {
                    $data['injury'] === 'serious' || $data['lameness'] === 3 => 'critical',
                    ! empty($data['pain']) || $data['lameness'] === 2 => 'high', default => 'medium',
                };
                $case = $this->openCase($data['animal_id'] ?? null, $data['pen_id'] ?? null, $kind, $severity, $data['notes'] ?? null, $by);
            }
            WelfareCheck::create($data + ['pain' => (bool) ($data['pain'] ?? false), 'case_id' => $case?->id, 'checked_by' => $by,
                'next_check_on' => $data['next_check_on'] ?? now()->addDays($issue ? 1 : 7)->toDateString()]);

            return $case;
        });

        return back()->with('success', $case ? "Issue found: welfare case {$case->label()} opened for triage." : 'Normal. Next inspection scheduled.');
    }

    /** A welfare case opened directly (inspection, animal report, injury or lameness alert). */
    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'animal_id' => ['nullable', 'required_without:pen_id', 'exists:animals,id'], 'pen_id' => ['nullable', 'exists:pens,id'],
            'issue' => ['required', Rule::in(array_keys(WelfareCase::ISSUES))], 'severity' => ['required', Rule::in(array_keys(WelfareCase::SEVERITY))],
            'triage' => ['nullable', 'string', 'max:1000'],
        ], [], ['animal_id' => 'goat or PEN']);
        $case = DB::transaction(fn () => $this->openCase($data['animal_id'] ?? null, $data['pen_id'] ?? null, $data['issue'], $data['severity'], $data['triage'] ?? null, $request->user()->name));

        return back()->with('success', "Welfare case {$case->label()} opened.");
    }

    private function openCase(?string $animalId, ?int $penId, string $issue, string $severity, ?string $triage, string $by): WelfareCase
    {
        DB::statement('LOCK TABLE welfare_cases IN SHARE ROW EXCLUSIVE MODE');
        $animal = $animalId ? Animal::find($animalId) : null;
        $repeat = WelfareCare::recurrenceOf($animalId, $issue); // D-199: the same problem again within 90 days goes to the vet
        if ($repeat && ! in_array($severity, WelfareCare::SERIOUS, true)) {
            $severity = 'high';
        }
        $case = WelfareCase::create([
            'case_no' => (int) WelfareCase::max('case_no') + 1, 'animal_id' => $animalId, 'pen_id' => $penId ?? $animal?->pen_id, 'opened_on' => now()->toDateString(),
            'issue' => $issue, 'severity' => $severity, 'status' => 'open', 'opened_by' => $by, 'recurrence_of_id' => $repeat,
            'triage' => $repeat ? trim('Repeat of '.WelfareCase::find($repeat)->label().'. '.($triage ?? '')) : $triage,
        ]);
        WelfareCare::holdTasks($case);
        if ($animal) {
            Farm::event($animal, 'health', 'Welfare case '.$case->label().': '.WelfareCase::ISSUES[$issue].' ('.strtolower(WelfareCase::SEVERITY[$severity]).')', $by);
        }
        if (in_array($severity, ['high', 'critical'], true)) { // BR-023
            Task::create([
                'title' => mb_substr("Welfare inspection: {$case->label()} ".($animal?->farm_number ?? $case->pen?->fullName() ?? ''), 0, 190), 'type' => 'health', 'priority' => $severity,
                'status' => 'open', 'due_on' => now()->toDateString(), 'animal_id' => $animalId, 'pen_id' => $case->pen_id,
                'department_id' => Department::where('name', 'Veterinary')->value('id'), 'notes' => mb_substr(WelfareCase::ISSUES[$issue].($triage ? ": {$triage}" : ''), 0, 500),
            ]);
        }

        return $case;
    }

    /** One step on the flow: vet referral, hoof / handling / habitat action, follow-up (improved?), escalate, close. */
    public function step(Request $request, WelfareCase $case): RedirectResponse
    {
        abort_if($case->status === 'closed', 422, 'This welfare case is closed.');
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_diff(array_keys(WelfareAction::KINDS), ['vet_update']))], 'notes' => ['nullable', 'string', 'max:1000'],
            'pen_id' => ['nullable', 'required_if:kind,housing', 'exists:pens,id'], 'assigned_user_id' => ['nullable', 'exists:users,id'], 'due_on' => ['nullable', 'date', 'after_or_equal:today'],
            'improved' => ['nullable', 'required_if:kind,follow_up', 'boolean'], 'evidence' => ['nullable', 'required_if:kind,close', 'string', 'max:1000'],
        ], ['evidence.required_if' => 'Closing a welfare case needs the evidence of recovery.']);
        $user = $request->user();
        if ($data['kind'] === 'handling_ok') { // D-199: only the vet lifts the hold on routine handling
            abort_unless($user->passes('clinical'), 403, 'The vet clears routine handling.');
        }
        if ($data['kind'] === 'housing') {
            abort_unless($case->animal_id, 422, 'Choose a goat case to move a goat.');
        }
        if ($data['kind'] === 'close' && $case->health_case_id && in_array(HealthCase::whereKey($case->health_case_id)->value('status'), HealthCase::ACTIVE, true)) {
            throw ValidationException::withMessages(['kind' => 'The vet\'s health case is still active: the vet\'s diagnosis and treatment come first.']);
        }
        if ($data['kind'] === 'close') {
            $improved = $case->status === 'follow_up' || $case->actions()->where('kind', 'follow_up')->where('improved', true)->exists();
            abort_unless($improved, 422, 'Confirm the welfare improvement with a follow-up before closing.');
            // Key control: a case under a vet plan is closed by the vet, the owner or the manager, not by a task alone.
            abort_if($case->health_record_id && ! ($user->passes('clinical') || $user->passes('manage_welfare_cases')), 403, 'A case under a vet plan is closed by the vet.');
        }
        DB::transaction(function () use ($case, $data, $user) {
            $animal = $case->animal;
            $task = null;
            if (in_array($data['kind'], ['hoof', 'handling', 'habitat'], true) && ! empty($data['assigned_user_id'])) { // D-199: an assigned care action
                $task = Task::create(['title' => mb_substr(WelfareAction::KINDS[$data['kind']].": welfare {$case->label()} ".($animal?->farm_number ?? $case->pen?->fullName() ?? ''), 0, 190), 'type' => 'health',
                    'priority' => $case->severity === 'low' ? 'medium' : $case->severity, 'status' => 'open', 'due_on' => $data['due_on'] ?? now()->toDateString(), 'user_id' => $data['assigned_user_id'],
                    'animal_id' => $case->animal_id, 'pen_id' => $case->pen_id, 'notes' => mb_substr($data['notes'] ?? 'Welfare care action', 0, 500)]);
            }
            WelfareAction::create(['case_id' => $case->id, 'kind' => $data['kind'], 'notes' => $data['notes'] ?? ($data['evidence'] ?? null), 'improved' => $data['improved'] ?? null, 'done_by' => $user->name, 'done_on' => now()->toDateString(),
                'assigned_user_id' => $data['assigned_user_id'] ?? null, 'due_on' => $data['due_on'] ?? null, 'task_id' => $task?->id, 'pen_id' => $data['pen_id'] ?? null]);
            if ($data['kind'] === 'housing') {
                Movements::transfer($animal, Pen::findOrFail($data['pen_id']), $user, ['care' => true, 'reason' => "Welfare care {$case->label()}"]);
            }
            match ($data['kind']) {
                'vet_referral' => $this->refer($case, $data['notes'] ?? null, $user->name),
                'hoof', 'handling', 'habitat' => $case->update(['status' => 'action']),
                'follow_up' => $case->update(['status' => $data['improved'] ? 'follow_up' : 'escalated']),
                'escalate' => $case->update(['status' => 'escalated']),
                'close' => $case->update(['status' => 'closed', 'closed_by' => $user->name, 'closed_at' => now(), 'closure_evidence' => $data['evidence']]),
                'housing' => $case->update(['status' => $case->status === 'open' ? 'action' : $case->status]),
                'handling_ok' => $case->update(['handling_cleared_by' => $user->name, 'handling_cleared_at' => now(), 'handling_note' => $data['notes'] ?? null]),
            };
            if (in_array($data['kind'], ['close', 'handling_ok'], true)) {
                WelfareCare::releaseTasks($case);
            } else {
                WelfareCare::holdTasks($case->fresh());
            }
            if (($data['kind'] === 'follow_up' && ! $data['improved']) || $data['kind'] === 'escalate') {
                Task::create([
                    'title' => mb_substr("Repeat clinical review: welfare {$case->label()} ".($animal?->farm_number ?? ''), 0, 190), 'type' => 'health', 'priority' => 'high', 'status' => 'open',
                    'due_on' => now()->toDateString(), 'animal_id' => $case->animal_id, 'pen_id' => $case->pen_id, 'department_id' => Department::where('name', 'Veterinary')->value('id'),
                    'notes' => 'No improvement after the welfare action. Reassess whether urgent veterinary referral is needed.',
                ]);
            }
            if ($animal) {
                Farm::event($animal, 'health', "Welfare {$case->label()}: ".WelfareAction::KINDS[$data['kind']], $user->name);
            }
        });

        return back()->with('success', WelfareAction::KINDS[$data['kind']].'.');
    }

    /** Urgent referral: the case goes to the vet's Reported Concerns (vet decides isolation and treatment). */
    private function refer(WelfareCase $case, ?string $notes, string $by): void
    {
        $record = $case->animal_id ? HealthRecord::create([
            'animal_id' => $case->animal_id, 'kind' => 'observation', 'occurred_on' => now()->toDateString(), 'title' => mb_substr('Welfare referral: '.WelfareCase::ISSUES[$case->issue], 0, 120),
            'notes' => trim(($case->triage ?? '')."\n".($notes ?? '')) ?: null, 'severity' => in_array($case->severity, ['high', 'critical'], true) ? 'high' : 'moderate',
            'recorded_by' => $by, 'recorded_role' => 'Welfare', 'status' => 'open',
        ]) : null;
        $case->update(['status' => 'vet_referral', 'health_record_id' => $record?->id]);
        Task::create([
            'title' => mb_substr("Vet plan for welfare {$case->label()} ".($case->animal?->farm_number ?? $case->pen?->fullName() ?? ''), 0, 190), 'type' => 'health', 'priority' => 'high',
            'status' => 'open', 'due_on' => now()->toDateString(), 'animal_id' => $case->animal_id, 'pen_id' => $case->pen_id, 'department_id' => Department::where('name', 'Veterinary')->value('id'),
            'notes' => 'Urgent welfare referral: isolate and treat under the vet plan as the vet decides.',
        ]);
    }
}
