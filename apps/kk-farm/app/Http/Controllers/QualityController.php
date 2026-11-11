<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\ComplianceItem;
use App\Models\Department;
use App\Models\EmergencyDrill;
use App\Models\FeedItem;
use App\Models\FeedUsage;
use App\Models\Incident;
use App\Models\Medicine;
use App\Models\MedicineMovement;
use App\Models\MilkLot;
use App\Models\MilkRecord;
use App\Models\QualityCapa;
use App\Models\QualityCase;
use App\Models\QualityHold;
use App\Models\Sop;
use App\Models\SopRevision;
use App\Models\Task;
use App\Models\TmrBatch;
use App\Models\Treatment;
use App\Models\User;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-189: App 21 Quality, Compliance & Safety, following V3 flow 21:
 * quality alarm / audit finding → record the affected animal, lot or process → immediate food / animal risk?
 * Yes: place hold / isolate / recall (contained). No: straight to root cause and evidence gathering → design CAPA with
 * owner and date → approve the action and execute → verify fix and traceability (independently: never the person who
 * executed it) → Fail: reopen or escalate the failing action, replan the CAPA. Pass: release (only if authorised) →
 * close the case and share the learning.
 * Key control: held food / animal products cannot sell (App\Support\Quality); CAPA requires independent verification.
 * Also: SOP library with append-only revisions (approved by someone other than the writer), the regulatory register
 * and emergency readiness drills (a failed drill opens a quality case).
 */
class QualityController extends Controller
{
    /** Owner, manager and vet act as the Quality & Compliance lead: contain, approve, verify, release, close. */
    private function lead(Request $request): bool
    {
        return in_array($request->user()->role, ['ceo', 'manager', 'vet'], true);
    }

    public function index(Request $request): Response
    {
        $from = now()->subDays(90);
        $cases = QualityCase::with(['animal', 'milkLot', 'feedItem', 'medicine', 'capas', 'holds'])
            ->where(fn ($q) => $q->where('status', '!=', 'closed')->orWhere('closed_at', '>=', $from))
            ->orderByRaw("case status when 'reopened' then 0 when 'open' then 1 when 'contained' then 2 when 'investigating' then 3 when 'capa' then 4 when 'verifying' then 5 when 'verified' then 6 else 7 end")
            ->orderByDesc('id')->get();
        $users = User::orderBy('name')->get(['id', 'name', 'role']);

        $capas = QualityCapa::where('created_at', '>=', $from)->get();
        $passed = $capas->where('status', 'passed');
        $overdue = $capas->filter(fn ($c) => in_array($c->status, ['planned', 'approved', 'executed'], true) && $c->due_on->lt(today()));
        $onTime = $passed->filter(fn ($c) => $c->verified_at && $c->verified_at->toDateString() <= $c->due_on->toDateString())->count();
        $tested = MilkLot::whereNotNull('tested_at')->where('tested_at', '>=', $from)->get(['id', 'status']);
        $lots = MilkLot::with('dispatches')->where('created_at', '>=', $from)->get();
        $traced = $lots->filter(fn ($l) => $l->session_id && $l->dispatches->every(fn ($d) => $d->kind === 'processing' || $d->customer_id || $d->buyer))->count();
        $register = ComplianceItem::where('active', true)->orderByRaw('expires_on is null, expires_on')->get();
        $drills = EmergencyDrill::orderByDesc('held_on')->limit(60)->get();
        $lastDrill = $drills->groupBy('scenario')->map->first();

        return Inertia::render('Quality', [
            'cases' => $cases->map(fn (QualityCase $c) => $this->caseRow($c)),
            'holds' => QualityHold::with(['qualityCase'])->active()->orderByDesc('placed_at')->get()->map(fn (QualityHold $h) => [
                'id' => $h->id, 'kind' => $h->kind, 'case' => $h->qualityCase?->label(), 'caseId' => $h->case_id, 'what' => $this->holdName($h),
                'by' => $h->placed_by, 'at' => $h->placed_at->format('j M Y'), 'caseVerified' => in_array($h->qualityCase?->status, ['verified'], true),
            ]),
            'sops' => Sop::with('revisions')->orderBy('code')->get()->map(fn (Sop $s) => [
                'id' => $s->id, 'code' => $s->code, 'title' => $s->title, 'area' => $s->area, 'version' => $s->version, 'active' => $s->active,
                'review' => $s->review_on?->toDateString(), 'reviewDue' => $s->review_on && $s->review_on->lte(today()),
                'current' => ($cur = $s->revisions->firstWhere('version', $s->version)) ? ['body' => $cur->body, 'by' => $cur->written_by, 'approvedBy' => $cur->approved_by, 'on' => $cur->approved_at?->format('j M Y')] : null,
                'pending' => ($p = $s->revisions->first(fn ($r) => $r->approved_at === null)) ? ['id' => $p->id, 'version' => $p->version, 'body' => $p->body, 'note' => $p->change_note, 'by' => $p->written_by] : null,
                'history' => $s->revisions->whereNotNull('approved_at')->map(fn (SopRevision $r) => ['version' => $r->version, 'note' => $r->change_note, 'by' => $r->written_by, 'approvedBy' => $r->approved_by, 'on' => $r->approved_at?->format('j M Y')])->values(),
            ]),
            'register' => $register->map(fn (ComplianceItem $i) => [
                'id' => $i->id, 'kind' => $i->kind, 'name' => $i->name, 'authority' => $i->authority, 'reference' => $i->reference, 'owner' => $i->owner, 'notes' => $i->notes,
                'issued' => $i->issued_on?->toDateString(), 'expires' => $i->expires_on?->toDateString(),
                'state' => ! $i->expires_on ? 'ok' : ($i->expires_on->lt(today()) ? 'expired' : ($i->expires_on->lte(today()->addDays(ComplianceItem::DUE_DAYS)) ? 'due' : 'ok')),
            ]),
            'drills' => $drills->map(fn (EmergencyDrill $d) => [
                'id' => $d->id, 'scenario' => $d->scenario, 'on' => $d->held_on->format('j M Y'), 'participants' => $d->participants, 'minutes' => $d->response_minutes,
                'passed' => $d->passed, 'findings' => $d->findings, 'next' => $d->next_due_on?->format('j M Y'), 'by' => $d->recorded_by,
                'caseId' => $d->case_id, 'latest' => $lastDrill[$d->scenario]?->id === $d->id, 'due' => $lastDrill[$d->scenario]?->id === $d->id && $d->next_due_on && $d->next_due_on->lte(today()),
            ]),
            'kpi' => [
                'openCases' => $cases->where('status', '!=', 'closed')->count(),
                'activeHolds' => QualityHold::active()->count(),
                'capaOnTime' => ($passed->count() + $overdue->count()) ? round($onTime / ($passed->count() + $overdue->count()) * 100) : null,
                'overdueCapas' => $overdue->count(),
                'traceCompleteness' => $lots->count() ? round($traced / $lots->count() * 100) : null,
                'rejectRate' => $tested->count() ? round($tested->where('status', 'rejected')->count() / $tested->count() * 100, 1) : null,
                'registerDue' => $register->filter(fn ($i) => $i->expires_on && $i->expires_on->lte(today()->addDays(ComplianceItem::DUE_DAYS)))->count(),
                'sopReviews' => Sop::where('active', true)->whereNotNull('review_on')->where('review_on', '<=', today())->count(),
                'drillsDue' => $lastDrill->filter(fn ($d) => $d->next_due_on && $d->next_due_on->lte(today()))->count(),
                'bySource' => QualityCase::where('opened_on', '>=', $from->toDateString())->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source'),
            ],
            'pick' => [
                'goats' => Animal::active()->orderBy('farm_number')->get(['id', 'farm_number']),
                'lots' => MilkLot::whereNotIn('status', ['rejected'])->where('created_at', '>=', now()->subDays(60))->orderByDesc('id')->get(['id', 'code', 'status']),
                'feeds' => FeedItem::where('active', true)->orderBy('name')->get(['id', 'name']),
                'medicines' => Medicine::orderBy('name')->get(['id', 'name']),
                'incidents' => Incident::where('occurred_at', '>=', $from)->orderByDesc('occurred_at')->get()->map(fn (Incident $i) => ['id' => $i->id, 'name' => $i->occurred_at->format('j M').' · '.(Incident::KINDS[$i->kind] ?? $i->kind).' · '.mb_substr($i->description ?? '', 0, 40)]),
                'users' => $users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            ],
            'options' => ['sources' => QualityCase::SOURCES, 'subjects' => QualityCase::SUBJECTS, 'severity' => QualityCase::SEVERITY, 'status' => QualityCase::STATUS,
                'holds' => QualityHold::KINDS, 'capaKinds' => QualityCapa::KINDS, 'capaStatus' => QualityCapa::STATUS, 'areas' => Sop::AREAS,
                'registerKinds' => ComplianceItem::KINDS, 'scenarios' => EmergencyDrill::SCENARIOS],
            'prefill' => $request->integer('incident') ? Incident::find($request->integer('incident'))?->only(['id', 'description', 'severity', 'animal_id']) : null,
            'me' => $request->user()->name,
            'isLead' => $this->lead($request),
        ]);
    }

    private function caseRow(QualityCase $c): array
    {
        return [
            'id' => $c->id, 'no' => $c->label(), 'source' => $c->source, 'title' => $c->title, 'description' => $c->description, 'subject' => $c->subject,
            'what' => $c->subjectName(), 'goatId' => $c->animal_id, 'risk' => $c->immediate_risk, 'severity' => $c->severity, 'status' => $c->status,
            'rootCause' => $c->root_cause, 'evidence' => $c->evidence, 'learning' => $c->learning, 'by' => $c->opened_by, 'opened' => $c->opened_on->format('j M Y'),
            'closedBy' => $c->closed_by, 'closedAt' => $c->closed_at?->format('j M Y'), 'incidentId' => $c->incident_id,
            'capas' => $c->capas->map(fn (QualityCapa $p) => [
                'id' => $p->id, 'kind' => $p->kind, 'action' => $p->action, 'owner' => $p->owner, 'due' => $p->due_on->format('j M Y'), 'overdue' => in_array($p->status, ['planned', 'approved', 'executed'], true) && $p->due_on->lt(today()),
                'status' => $p->status, 'plannedBy' => $p->planned_by, 'approvedBy' => $p->approved_by, 'executedBy' => $p->executed_by, 'notes' => $p->execution_notes,
                'verifiedBy' => $p->verified_by, 'verification' => $p->verification, 'replaced' => $c->capas->contains('replaces_id', $p->id),
            ])->values(),
            'holds' => $c->holds->map(fn (QualityHold $h) => ['id' => $h->id, 'kind' => $h->kind, 'what' => $this->holdName($h), 'active' => $h->released_at === null,
                'outcome' => $h->outcome, 'releasedBy' => $h->released_by, 'note' => $h->release_note])->values(),
            'trace' => $this->trace($c),
        ];
    }

    private function holdName(QualityHold $h): string
    {
        return match ($h->subject) {
            'animal' => 'Goat '.(Animal::find($h->animal_id)?->farm_number ?? '?'),
            'milk_lot' => 'Milk lot '.(MilkLot::find($h->milk_lot_id)?->code ?? '?'),
            'feed_item' => 'Feed '.(FeedItem::find($h->feed_item_id)?->name ?? '?'),
            'medicine' => 'Medicine '.(Medicine::find($h->medicine_id)?->name ?? '?').($h->batch ? " batch {$h->batch}" : ' (all batches)'),
            default => '?',
        };
    }

    /** Backward and forward trace of the affected subject (the "verify fix and traceability" step reads this). @return array<int, string> */
    private function trace(QualityCase $c): array
    {
        $since = $c->opened_on->copy()->subDays(30)->toDateString();

        return match ($c->subject) {
            'milk_lot' => $c->milkLot ? array_values(array_filter([
                $c->milkLot->session ? 'From the '.$c->milkLot->session->session.' session of '.$c->milkLot->session->milked_on->format('j M Y') : 'Session not linked',
                ($does = MilkRecord::with('animal')->where('session_id', $c->milkLot->session_id)->whereNotNull('session_id')->get()->map(fn ($r) => $r->animal?->farm_number)->filter()->unique()->implode(', ')) ? "Does: {$does}" : null,
                'Status: '.(MilkLot::STATUS[$c->milkLot->status] ?? $c->milkLot->status).' · '.$c->milkLot->litres.' L',
                ...$c->milkLot->dispatches()->with('customer')->get()->map(fn ($d) => 'Dispatched '.$d->dispatched_on->format('j M').": {$d->litres} L to ".($d->kind === 'processing' ? 'processing' : ($d->customer?->name ?? $d->buyer ?? 'unknown buyer')))->all(),
            ])) : [],
            'animal' => $c->animal ? [
                'Pen: '.($c->animal->pen?->fullName() ?? 'none'),
                ...Treatment::where('animal_id', $c->animal_id)->where('given_on', '>=', $since)->orderByDesc('given_on')->get()
                    ->map(fn ($t) => 'Treated '.$t->given_on->format('j M').": {$t->product} {$t->dose}".($t->meat_clear_on ? ' · meat clear '.$t->meat_clear_on->format('j M') : '').($t->milk_clear_on ? ' · milk clear '.$t->milk_clear_on->format('j M') : ''))->all(),
            ] : [],
            'feed_item' => $c->feedItem ? [
                ...FeedUsage::with('pen.shed')->where('feed_item_id', $c->feed_item_id)->where('fed_on', '>=', $since)->whereNotIn('id', FeedUsage::whereNotNull('supersedes_id')->select('supersedes_id'))->get()->groupBy('pen_id')
                    ->map(fn ($g) => 'Fed to '.($g->first()->pen?->fullName() ?? '?').': '.round($g->sum('offered_kg'), 1).' KG since '.$g->min('fed_on')->format('j M'))->values()->all(),
                ...TmrBatch::whereIn('id', \App\Models\FeedStockMovement::where('feed_item_id', $c->feed_item_id)->whereNotNull('tmr_batch_id')->select('tmr_batch_id'))->where('mixed_on', '>=', $since)->get()->map(fn ($b) => "In TMR batch {$b->batch_no} ({$b->total_kg} KG)")->all(),
            ] : [],
            'medicine' => $c->medicine ? [
                ...MedicineMovement::where('medicine_id', $c->medicine_id)->when($c->batch, fn ($q) => $q->where('batch', $c->batch))->where('type', 'issue')->where('moved_on', '>=', $since)->orderByDesc('moved_on')->limit(20)->get()
                    ->map(fn ($m) => 'Issued '.$m->moved_on->format('j M').': '.abs((float) $m->qty).($m->batch ? " (batch {$m->batch})" : '').($m->reference ? " · {$m->reference}" : ''))->all(),
                ...Treatment::with('animal')->where('product', 'ilike', $c->medicine->name.'%')->where('given_on', '>=', $since)->get()->map(fn ($t) => 'Given to '.($t->animal?->farm_number ?? '?').' on '.$t->given_on->format('j M'))->all(),
            ] : [],
            default => [],
        };
    }

    /** Quality alarm / audit finding → record the affected animal, lot or process → immediate risk? → contain or investigate. */
    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(array_keys(QualityCase::SOURCES))], 'title' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:2000'],
            'subject' => ['required', Rule::in(array_keys(QualityCase::SUBJECTS))], 'severity' => ['required', Rule::in(array_keys(QualityCase::SEVERITY))],
            'animal_id' => ['nullable', 'required_if:subject,animal', 'exists:animals,id'], 'milk_lot_id' => ['nullable', 'required_if:subject,milk_lot', 'exists:milk_lots,id'],
            'feed_item_id' => ['nullable', 'required_if:subject,feed_item', 'exists:feed_items,id'], 'medicine_id' => ['nullable', 'required_if:subject,medicine', 'exists:medicines,id'],
            'batch' => ['nullable', 'string', 'max:40'], 'process' => ['nullable', 'required_if:subject,process', 'string', 'max:160'], 'incident_id' => ['nullable', 'exists:incidents,id'],
            'immediate_risk' => ['required', 'boolean'], 'containment' => ['nullable', 'required_if:immediate_risk,true', 'required_if:immediate_risk,1', Rule::in(array_keys(QualityHold::KINDS))],
        ], ['containment.required_if' => 'An immediate food or animal risk needs a hold, isolation or recall.', 'process.required_if' => 'Describe the affected process.']);
        if ($data['immediate_risk'] && ! $this->lead($request) && $data['containment'] === 'recall') {
            abort(403, 'Only the quality lead recalls a lot.');
        }
        $case = \App\Support\QualityCases::open($data, $request->user()); // D-196: shared with the automatic triggers

        return back()->with('success', $case->immediate_risk ? "{$case->label()} recorded and contained. Gather the root cause and evidence." : "{$case->label()} recorded. Gather the root cause and evidence.");
    }

    /** Place hold / isolate / recall. A held product cannot sell or be used until the lead releases it. */
    private function contain(QualityCase $case, string $kind, User $by): QualityHold
    {
        return \App\Support\QualityCases::contain($case, $kind, $by);
    }

    private function task(string $title, string $priority, string $department, string $notes, QualityCase $case, ?string $animalId = null, ?int $userId = null): void
    {
        \App\Support\QualityCases::task($title, $priority, $department, $notes, $case, $animalId, $userId);
    }

    /** Add a further containment to an open case (e.g. the risk became clear during investigation). */
    public function hold(Request $request, QualityCase $case): RedirectResponse
    {
        abort_unless($this->lead($request), 403, 'The quality lead places holds.');
        abort_if($case->status === 'closed', 422, 'This case is closed.');
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(QualityHold::KINDS))]]);
        abort_if($case->holds()->active()->where('kind', $data['kind'])->exists(), 422, 'That containment is already active.');
        DB::transaction(function () use ($case, $data, $request) {
            $this->contain($case, $data['kind'], $request->user());
            $case->update(['immediate_risk' => true]);
        });

        return back()->with('success', QualityHold::KINDS[$data['kind']]." placed on {$case->subjectName()}.");
    }

    /** Root cause and evidence gathering. */
    public function investigate(Request $request, QualityCase $case): RedirectResponse
    {
        abort_if($case->status === 'closed', 422, 'This case is closed.');
        $data = $request->validate(['root_cause' => ['required', 'string', 'max:2000'], 'evidence' => ['required', 'string', 'max:2000']],
            ['evidence.required' => 'Record the evidence (test result, photos, records checked).']);
        $case->update($data + ['status' => in_array($case->status, ['open', 'contained', 'investigating'], true) ? 'capa' : $case->status]);

        return back()->with('success', "{$case->label()}: root cause recorded. Design the CAPA with an owner and a date.");
    }

    /** Design CAPA with owner and date (also the "replan" after a failed verification). */
    public function capa(Request $request, QualityCase $case): RedirectResponse
    {
        abort_if($case->status === 'closed', 422, 'This case is closed.');
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(QualityCapa::KINDS))], 'action' => ['required', 'string', 'max:1000'],
            'owner_id' => ['required', 'exists:users,id'], 'due_on' => ['required', 'date', 'after_or_equal:today'], 'replaces_id' => ['nullable', 'exists:quality_capas,id'],
        ], [], ['owner_id' => 'owner']);
        if (! $case->root_cause) {
            throw ValidationException::withMessages(['action' => 'Record the root cause and evidence before designing the CAPA.']);
        }
        if (! empty($data['replaces_id'])) {
            $old = QualityCapa::where('case_id', $case->id)->findOrFail($data['replaces_id']);
            abort_unless($old->status === 'failed', 422, 'Only a failed action is replanned.');
        }
        $owner = User::findOrFail($data['owner_id']);
        QualityCapa::create(['case_id' => $case->id, 'kind' => $data['kind'], 'action' => $data['action'], 'owner' => $owner->name, 'due_on' => $data['due_on'],
            'status' => 'planned', 'planned_by' => $request->user()->name, 'replaces_id' => $data['replaces_id'] ?? null]);
        $case->update(['status' => 'capa']);

        return back()->with('success', "CAPA planned for {$owner->name}, due ".date('j M', strtotime($data['due_on'])).'. It needs approval.');
    }

    /** Approve action: the quality lead, not the person who planned it (BR-027) except the owner of the farm. */
    public function approve(Request $request, QualityCapa $capa): RedirectResponse
    {
        abort_unless($this->lead($request), 403, 'The quality lead approves CAPAs.');
        abort_unless($capa->status === 'planned', 422, 'Only a planned action is approved.');
        abort_if($capa->planned_by === $request->user()->name && $request->user()->role !== 'ceo', 403, 'Someone other than the planner approves the action.');
        $capa->update(['status' => 'approved', 'approved_by' => $request->user()->name, 'approved_at' => now()]);
        $case = $capa->qualityCase;
        $this->task("CAPA {$case->label()}: ".mb_substr($capa->action, 0, 120), 'medium', 'Management', "Due {$capa->due_on->format('j M Y')}. Owner: {$capa->owner}.", $case, null, User::where('name', $capa->owner)->value('id'));

        return back()->with('success', "Approved. {$capa->owner} executes it by {$capa->due_on->format('j M')}.");
    }

    /** Execute: the owner (or the lead) records what was done. */
    public function execute(Request $request, QualityCapa $capa): RedirectResponse
    {
        abort_unless($capa->status === 'approved', 422, 'Only an approved action is executed.');
        abort_unless($capa->owner === $request->user()->name || $this->lead($request), 403, 'The action owner records the execution.');
        $data = $request->validate(['notes' => ['required', 'string', 'max:1000']], ['notes.required' => 'Say what was done.']);
        $capa->update(['status' => 'executed', 'executed_by' => $request->user()->name, 'executed_at' => now(), 'execution_notes' => $data['notes']]);
        $capa->qualityCase->update(['status' => 'verifying']);

        return back()->with('success', 'Executed. Someone independent verifies the fix and traceability.');
    }

    /** Verify fix and traceability: independent (never the person who executed it). Fail → reopen / escalate. */
    public function verify(Request $request, QualityCapa $capa): RedirectResponse
    {
        abort_unless($this->lead($request), 403, 'The quality lead verifies CAPAs.');
        abort_unless($capa->status === 'executed', 422, 'Only an executed action is verified.');
        abort_if($capa->executed_by === $request->user()->name, 403, 'CAPA needs independent verification: someone other than the person who executed it.');
        $data = $request->validate(['pass' => ['required', 'boolean'], 'notes' => ['required', 'string', 'max:1000']], ['notes.required' => 'Record what was checked.']);
        $case = $capa->qualityCase;
        DB::transaction(function () use ($capa, $data, $request, $case) {
            $capa->update(['status' => $data['pass'] ? 'passed' : 'failed', 'verified_by' => $request->user()->name, 'verified_at' => now(), 'verification' => $data['notes']]);
            if (! $data['pass']) {
                $case->update(['status' => 'reopened']);
                $this->task("Escalated: CAPA failed on {$case->label()}", 'high', 'Management', 'Verification failed: '.$data['notes'].'. Replan the action.', $case);

                return;
            }
            $case->refresh()->load('capas');
            $open = $case->capas->filter(fn ($p) => $p->status !== 'passed' && ! ($p->status === 'failed' && $case->capas->contains('replaces_id', $p->id)));
            $case->update(['status' => $open->isEmpty() ? 'verified' : 'verifying']);
        });

        return back()->with('success', $data['pass'] ? 'Verified. '.($case->fresh()->status === 'verified' ? 'Release the holds (if authorised) and close the case.' : 'Other actions are still open.') : 'Failed: the case is reopened and escalated. Replan the action.');
    }

    /** Release only if authorised: after the fix is verified; disposal is allowed at any time. */
    public function release(Request $request, QualityHold $hold): RedirectResponse
    {
        abort_unless($this->lead($request), 403, 'Only the quality lead releases a hold.');
        abort_if($hold->released_at !== null, 422, 'This hold is already released.');
        $data = $request->validate(['outcome' => ['required', Rule::in(['released', 'disposed'])], 'note' => ['required', 'string', 'max:500']]);
        $case = $hold->qualityCase;
        if ($data['outcome'] === 'released') {
            abort_unless($case->status === 'verified' || ($case->capas()->doesntExist() && $case->root_cause), 422, 'Release only after the fix is verified (or, with no action needed, after the root cause is recorded).');
            abort_if($hold->kind === 'recall', 422, 'A recalled lot is not released back for sale. Record it as disposed.');
        }
        DB::transaction(function () use ($hold, $data, $request) {
            $hold->update(['outcome' => $data['outcome'], 'released_by' => $request->user()->name, 'released_at' => now(), 'release_note' => $data['note']]);
            if ($hold->milk_lot_id && ($lot = MilkLot::find($hold->milk_lot_id))) {
                if ($data['outcome'] === 'disposed' && $lot->status !== 'recalled') {
                    $lot->update(['status' => 'rejected', 'hold_reason' => 'Disposed: '.$data['note']]);
                }
                // Released: the lot stays on Hold in Dairy, where it is retested before release (flow 17).
            }
        });

        return back()->with('success', $data['outcome'] === 'released' ? 'Hold released.'.($hold->milk_lot_id ? ' Retest the lot in Dairy before release.' : '') : 'Recorded as disposed.');
    }

    /** Close case and share learning: every action verified and no active hold. */
    public function close(Request $request, QualityCase $case): RedirectResponse
    {
        abort_unless($this->lead($request), 403, 'The quality lead closes cases.');
        abort_if($case->status === 'closed', 422, 'Already closed.');
        $data = $request->validate(['learning' => ['required', 'string', 'max:2000']], ['learning.required' => 'Share the learning: what changes so it does not happen again.']);
        $ready = $case->status === 'verified' || ($case->capas()->doesntExist() && $case->root_cause);
        abort_unless($ready, 422, 'Verify every action first (or record the root cause when no action is needed).');
        abort_if($case->holds()->active()->exists(), 422, 'Release or dispose of every hold before closing.');
        $case->update(['status' => 'closed', 'learning' => $data['learning'], 'closed_by' => $request->user()->name, 'closed_at' => now()]);
        Farm::log($request->user(), "Closed quality case {$case->label()}", '/quality?tab=cases');

        return back()->with('success', "{$case->label()} closed. The learning is shared in Closed Cases.");
    }

    public function sop(Request $request): RedirectResponse
    {
        abort_unless($this->lead($request), 403);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('sops', 'code')->where('org_id', \App\Support\Tenant::current())], 'title' => ['required', 'string', 'max:120'],
            'area' => ['required', Rule::in(array_keys(Sop::AREAS))], 'body' => ['required', 'string', 'max:20000'], 'review_on' => ['nullable', 'date', 'after:today'],
        ]);
        DB::transaction(function () use ($data, $request) {
            $sop = Sop::create(['code' => strtoupper($data['code']), 'title' => $data['title'], 'area' => $data['area'], 'review_on' => $data['review_on'] ?? now()->addYear()->toDateString()]);
            SopRevision::create(['sop_id' => $sop->id, 'version' => 1, 'body' => $data['body'], 'change_note' => 'First issue', 'written_by' => $request->user()->name]);
        });

        return back()->with('success', 'SOP drafted. Someone else approves version 1.');
    }

    /** A new revision (append-only). Raised from a CAPA when case_id is given. */
    public function revise(Request $request, Sop $sop): RedirectResponse
    {
        abort_unless($this->lead($request), 403);
        abort_if($sop->revisions()->whereNull('approved_at')->exists(), 422, 'A revision is already waiting for approval.');
        $data = $request->validate(['body' => ['required', 'string', 'max:20000'], 'change_note' => ['required', 'string', 'max:500'], 'case_id' => ['nullable', 'exists:quality_cases,id']]);
        SopRevision::create($data + ['sop_id' => $sop->id, 'version' => (int) $sop->revisions()->max('version') + 1, 'written_by' => $request->user()->name]);

        return back()->with('success', 'Revision drafted. Someone else approves it.');
    }

    public function approveSop(Request $request, SopRevision $revision): RedirectResponse
    {
        abort_unless($this->lead($request), 403);
        abort_if($revision->approved_at !== null, 422, 'Already approved.');
        abort_if($revision->written_by === $request->user()->name && $request->user()->role !== 'ceo', 403, 'Someone other than the writer approves the SOP.');
        DB::transaction(function () use ($revision, $request) {
            $revision->update(['approved_by' => $request->user()->name, 'approved_at' => now()]);
            $revision->sop->update(['version' => $revision->version, 'review_on' => now()->addYear()->toDateString()]);
        });

        return back()->with('success', "{$revision->sop->code} version {$revision->version} is now in force.");
    }

    public function register(Request $request, ?ComplianceItem $item = null): RedirectResponse
    {
        abort_unless($this->lead($request) || $request->user()->role === 'finance', 403);
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(ComplianceItem::KINDS))], 'name' => ['required', 'string', 'max:120'], 'authority' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:60'], 'issued_on' => ['nullable', 'date'], 'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'owner' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:500'], 'active' => ['boolean'],
        ]);
        $item?->exists ? $item->update($data) : ComplianceItem::create($data);

        return back()->with('success', 'Regulatory register saved.');
    }

    /** Emergency readiness drill. A failed drill opens a quality case for the root cause and CAPA. */
    public function drill(Request $request): RedirectResponse
    {
        abort_unless($this->lead($request), 403);
        $data = $request->validate([
            'scenario' => ['required', Rule::in(array_keys(EmergencyDrill::SCENARIOS))], 'held_on' => ['required', 'date', 'before_or_equal:today'],
            'participants' => ['required', 'integer', 'min:1', 'max:500'], 'response_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'passed' => ['required', 'boolean'], 'findings' => ['nullable', 'required_if:passed,false', 'required_if:passed,0', 'string', 'max:1000'], 'next_due_on' => ['nullable', 'date', 'after:held_on'],
        ], ['findings.required_if' => 'Record what went wrong in the drill.']);
        DB::transaction(function () use ($data, $request) {
            $caseId = null;
            if (! $data['passed']) {
                DB::statement('LOCK TABLE quality_cases IN SHARE ROW EXCLUSIVE MODE');
                $caseId = QualityCase::create([
                    'case_no' => (int) QualityCase::max('case_no') + 1, 'source' => 'drill', 'title' => 'Failed drill: '.EmergencyDrill::SCENARIOS[$data['scenario']],
                    'description' => $data['findings'], 'subject' => 'process', 'process' => 'Emergency response: '.EmergencyDrill::SCENARIOS[$data['scenario']],
                    'immediate_risk' => false, 'severity' => 'medium', 'status' => 'investigating', 'opened_by' => $request->user()->name, 'opened_on' => now()->toDateString(),
                ])->id;
            }
            EmergencyDrill::create($data + ['next_due_on' => $data['next_due_on'] ?? now()->parse($data['held_on'])->addMonths(6)->toDateString(), 'case_id' => $caseId, 'recorded_by' => $request->user()->name]);
        });

        return back()->with('success', $data['passed'] ? 'Drill recorded.' : 'Drill failed: a quality case was opened for the root cause and CAPA.');
    }
}
