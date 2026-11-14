<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Department;
use App\Models\Disease;
use App\Models\HealthCase;
use App\Models\HealthRecord;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\Outbreak;
use App\Models\Pen;
use App\Models\Task;
use App\Support\Biosecurity;
use App\Support\Checkups;
use App\Support\Farm;
use App\Support\Intake;
use App\Support\Movements;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-193: V3 cross-app journey D Disease-to-Recovery (flow 27):
 * worker reports symptoms / abnormal scan → triage severity and contagion risk → high risk: immediate isolate / emergency
 * alert; routine: straight on → open veterinary case and investigations (lab) → specify treatment / isolation protocol →
 * issue medicine lot and administer (Health → Treatment, FEFO, vet-set withdrawal) → record withdrawal or movement
 * restriction → monitor and follow up → not recovered: extend treatment / lab / mortality record (reassess); recovered:
 * vet clears the goat for suitable housing → post medication + labour / animal cost → close the case and outbreak report.
 * Key control: isolate as needed, withdrawal set by the vet, affected pens restricted until cleared (Biosecurity).
 */
class DiseaseController extends Controller
{
    private function vet(Request $request): void
    {
        abort_unless($request->user()->passes('clinical'), 403, 'Only a veterinarian does this.');
    }

    public function index(Request $request): Response
    {
        $org = Organization::findOrFail(Tenant::current());
        $rate = (int) $org->labour_rate_minor;
        $from = now()->subDays(90)->toDateString();
        $active = HealthCase::with(['animal.pen.shed', 'treatments', 'outbreak', 'restrictedPen.shed'])->whereIn('status', HealthCase::ACTIVE)->where('condition', '!=', Intake::CONDITION)
            ->orderByDesc('emergency')->orderByRaw("case contagion when 'confirmed' then 0 when 'suspected' then 1 else 2 end")->orderBy('onset_on')->get();
        $resolved = HealthCase::with('treatments')->where('condition', '!=', Intake::CONDITION)->whereNotNull('resolved_on')->where('resolved_on', '>=', $from)->get();
        $recovered = $resolved->where('status', 'recovered');
        $herd = max(1, Animal::active()->count());
        $intake = HealthCase::where('condition', Intake::CONDITION)->where('status', 'closed')->where('resolved_on', '>=', $from)->get();
        $days = Intake::days();

        return Inertia::render('Disease', [
            'concerns' => HealthRecord::with('animal.pen.shed')->where('kind', 'observation')->where('status', 'open')->orderByDesc('occurred_on')->limit(50)->get()
                ->filter(fn ($r) => $r->animal && $r->animal->status === 'active')->map(fn (HealthRecord $r) => [
                    'id' => $r->id, 'goatId' => $r->animal_id, 'number' => $r->animal->farm_number, 'pen' => $r->animal->pen?->fullName(), 'title' => $r->title, 'notes' => $r->notes,
                    'severity' => $r->severity, 'by' => $r->recorded_by, 'on' => $r->occurred_on?->format('d/m/Y'),
                ])->values(),
            'cases' => $active->map(fn (HealthCase $c) => $this->row($c, $rate))->values(),
            'restricted' => collect(Biosecurity::restrictedPens())->map(fn ($cases, $penId) => ['pen' => Pen::with('shed')->find($penId)?->fullName(), 'cases' => $cases,
                'goats' => Animal::active()->where('pen_id', $penId)->count()])->values(),
            'outbreaks' => Outbreak::with(['cases.animal', 'cases.treatments'])->where(fn ($q) => $q->where('status', 'open')->orWhere('closed_on', '>=', now()->subDays(180)->toDateString()))->orderByDesc('id')->get()
                ->map(fn (Outbreak $o) => $this->outbreakRow($o, $rate)),
            'kpi' => [
                'active' => $active->count(), 'emergencies' => $active->where('emergency', true)->count(), 'restrictedPens' => count(Biosecurity::restrictedPens()),
                'recoveryDays' => $recovered->count() ? round($recovered->avg(fn ($c) => $c->onset_on->diffInDays($c->resolved_on)), 1) : null,
                'attackRate' => round(HealthCase::where('condition', '!=', Intake::CONDITION)->where('onset_on', '>=', $from)->distinct('animal_id')->count('animal_id') / $herd * 100, 1),
                'caseCost' => $resolved->count() ? round($resolved->avg(fn ($c) => $c->costMinor($rate)['total']) / 100) : null,
                'releaseLag' => $intake->count() ? round($intake->avg(fn ($c) => max(0, $c->onset_on->diffInDays($c->resolved_on) - $days)), 1) : null,
                'deaths' => $resolved->where('status', 'deceased')->count(),
            ],
            'diseases' => Disease::where('active', true)->orderBy('name')->get(['name', 'contagious', 'notifiable', 'zoonotic']),
            'conditions' => HealthCase::CONDITIONS,
            'pens' => Pen::with('shed')->where('kind', 'pen')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName(), 'isolation' => in_array($p->shed?->type, ['VCU', 'BSU'], true) || str_starts_with((string) $p->name, 'ISO')])
                ->sortBy([['isolation', 'desc'], ['name', 'asc']])->values(),
            'options' => ['severity' => HealthCase::SEVERITIES, 'contagion' => HealthCase::CONTAGION, 'status' => HealthCase::STATUSES],
            'labourRate' => $rate / 100,
            'isVet' => $request->user()->passes('clinical'),
        ]);
    }

    private function row(HealthCase $c, int $rate): array
    {
        $cost = $c->costMinor($rate);

        return [
            'id' => $c->id, 'no' => $c->label(), 'goatId' => $c->animal_id, 'number' => $c->animal?->farm_number, 'pen' => $c->animal?->pen?->fullName(), 'condition' => $c->condition,
            'severity' => $c->severity, 'contagion' => $c->contagion, 'emergency' => $c->emergency, 'isolated' => $c->isolated, 'status' => $c->status, 'onset' => $c->onset_on->format('d/m/Y'),
            'days' => $c->ageDays(), 'review' => $c->review_on?->format('d/m/Y'), 'reviewDue' => $c->review_on && $c->review_on->lte(today()), 'diagnosis' => $c->diagnosis,
            'restrictedPen' => $c->restrictedPen?->fullName(), 'outbreak' => $c->outbreak?->code, 'lab' => $c->lab_test ? ['test' => $c->lab_test, 'on' => $c->lab_sampled_on?->format('d/m'), 'result' => $c->lab_result] : null,
            'treatments' => $c->treatments->map(fn ($t) => ['on' => $t->given_on->format('d/m'), 'product' => $t->product, 'dose' => $t->dose, 'meat' => $t->meat_clear_on?->format('d/m'), 'milk' => $t->milk_clear_on?->format('d/m')])->values(),
            'cost' => ['medicine' => $cost['medicine'] / 100, 'lab' => $cost['lab'] / 100, 'labour' => $cost['labour'] / 100, 'total' => $cost['total'] / 100],
        ];
    }

    private function outbreakRow(Outbreak $o, int $rate): array
    {
        $cases = $o->cases;

        return [
            'id' => $o->id, 'code' => $o->code, 'condition' => $o->condition, 'status' => $o->status, 'opened' => $o->opened_on->format('d/m/Y'), 'closed' => $o->closed_on?->format('d/m/Y'),
            'cases' => $cases->count(), 'active' => $cases->whereIn('status', HealthCase::ACTIVE)->count(), 'recovered' => $cases->where('status', 'recovered')->count(),
            'deaths' => $cases->where('status', 'deceased')->count(), 'population' => $o->population,
            'attackRate' => $o->population ? round($cases->count() / $o->population * 100, 1) : null,
            'cost' => round($cases->sum(fn ($c) => $c->costMinor($rate)['total']) / 100),
            'goats' => $cases->map(fn ($c) => $c->animal?->farm_number)->filter()->values(), 'report' => $o->report, 'by' => $o->opened_by, 'closedBy' => $o->closed_by,
        ];
    }

    /** Triage severity and contagion risk → open the veterinary case; high risk isolates now and raises the emergency alert. */
    public function triage(Request $request): RedirectResponse
    {
        $this->vet($request);
        $data = $request->validate([
            'animal_id' => ['required', 'exists:animals,id'], 'report_id' => ['nullable', 'exists:health_records,id'], 'condition' => ['required', 'string', 'max:120'],
            'symptoms' => ['nullable', 'string', 'max:1000'], 'severity' => ['required', Rule::in(array_keys(HealthCase::SEVERITIES))], 'contagion' => ['required', Rule::in(array_keys(HealthCase::CONTAGION))],
            'isolate_pen_id' => ['nullable', Rule::exists('pens', 'id')->where('kind', 'pen')], 'restrict_pen' => ['boolean'], 'declare_outbreak' => ['boolean'],
        ], [], ['isolate_pen_id' => 'isolation PEN']);
        $animal = Animal::findOrFail($data['animal_id']);
        $user = $request->user();
        $highRisk = $data['contagion'] !== 'none' || in_array($data['severity'], ['high', 'emergency'], true);
        $case = DB::transaction(function () use ($data, $animal, $user, $highRisk) {
            DB::statement('LOCK TABLE health_cases IN SHARE ROW EXCLUSIVE MODE');
            $homePen = $animal->pen_id;
            $outbreak = null;
            if ($data['contagion'] !== 'none') {
                $outbreak = Outbreak::where('status', 'open')->where('condition', $data['condition'])->first();
                if (! $outbreak && ! empty($data['declare_outbreak'])) {
                    $n = Outbreak::where('code', 'like', 'OB-'.now()->format('Y').'-%')->count() + 1;
                    $outbreak = Outbreak::create(['code' => 'OB-'.now()->format('Y').'-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'condition' => $data['condition'], 'opened_on' => now()->toDateString(),
                        'population' => Animal::active()->count(), 'opened_by' => $user->name]);
                }
            }
            $case = HealthCase::create([
                'case_no' => (int) HealthCase::max('case_no') + 1, 'animal_id' => $animal->id, 'onset_on' => now()->toDateString(), 'condition' => $data['condition'], 'symptoms' => $data['symptoms'] ?? null,
                'severity' => $data['severity'], 'contagion' => $data['contagion'], 'emergency' => $highRisk && $data['contagion'] !== 'none', 'isolated' => ! empty($data['isolate_pen_id']),
                'status' => 'open', 'vet' => $user->name, 'recorded_by' => $user->name, 'report_id' => $data['report_id'] ?? null,
                'restricted_pen_id' => ! empty($data['restrict_pen']) && $homePen ? $homePen : null, 'outbreak_id' => $outbreak?->id, 'review_on' => now()->addDays(3)->toDateString(),
            ]);
            if (! empty($data['report_id'])) {
                HealthRecord::whereKey($data['report_id'])->update(['status' => 'closed']);
            }
            if (! empty($data['isolate_pen_id']) && (int) $data['isolate_pen_id'] !== (int) $homePen) {
                Movements::transfer($animal, Pen::findOrFail($data['isolate_pen_id']), $user, ['care' => true, 'reason' => "Isolation: {$case->label()} {$data['condition']}"]);
            }
            if ($case->emergency) {
                foreach (['Veterinary' => 'Isolation protocol, PPE, footbath and treatment as the vet decides. Check PEN mates.', 'Management' => 'Emergency: no movements or sales from the affected pen until the vet clears it.'] as $dept => $what) {
                    Task::create(['title' => mb_substr("EMERGENCY {$case->label()}: {$data['condition']} ({$animal->farm_number})", 0, 190), 'type' => 'health', 'priority' => 'critical', 'status' => 'open',
                        'due_on' => now()->toDateString(), 'animal_id' => $animal->id, 'pen_id' => $homePen, 'department_id' => Department::where('name', $dept)->value('id'), 'notes' => $what]);
                }
            }
            if (Disease::where('name', $data['condition'])->where('notifiable', true)->exists()) {
                Task::create(['title' => mb_substr("Notify the Livestock Department: {$data['condition']} ({$case->label()})", 0, 190), 'type' => 'health', 'priority' => 'high', 'status' => 'open',
                    'due_on' => now()->toDateString(), 'animal_id' => $animal->id, 'department_id' => Department::where('name', 'Management')->value('id'), 'notes' => 'A notifiable disease is suspected. Report it as the regulations require.']);
            }
            Farm::event($animal, 'health', "Health case {$case->label()} opened: {$case->condition} (".HealthCase::SEVERITIES[$case->severity].', '.strtolower(HealthCase::CONTAGION[$case->contagion]).')', $user->name);
            Checkups::syncHealth($animal->fresh());

            return $case;
        });
        Farm::log($user, "Triaged {$animal->farm_number}: {$case->label()}", '/disease');

        return back()->with('success', "{$case->label()} opened".($case->emergency ? ': emergency alert sent' : '').($case->restricted_pen_id ? ', PEN under movement restriction' : '').'.');
    }

    /** Investigations: lab test and result; the lab fee posts once to Veterinary services. */
    public function lab(Request $request, HealthCase $case): RedirectResponse
    {
        $this->vet($request);
        $data = $request->validate(['lab_test' => ['required', 'string', 'max:120'], 'lab_sampled_on' => ['required', 'date', 'before_or_equal:today'], 'lab_result' => ['nullable', 'string', 'max:500'],
            'lab_cost' => ['nullable', 'numeric', 'min:0', 'max:10000000'], 'contagion' => ['nullable', Rule::in(array_keys(HealthCase::CONTAGION))]]);
        DB::transaction(function () use ($case, $data, $request) {
            $cost = isset($data['lab_cost']) ? (int) round($data['lab_cost'] * 100) : $case->lab_cost_minor;
            $ledger = $case->lab_ledger_entry_id;
            if (! $ledger && $cost > 0) {
                $ledger = LedgerEntry::create(['direction' => 'out', 'category' => 'Veterinary', 'amount' => $cost / 100, 'occurred_on' => $data['lab_sampled_on'], 'counterparty' => 'Laboratory',
                    'description' => "Lab {$data['lab_test']} for {$case->label()}", 'animal_id' => $case->animal_id, 'recorded_by' => $request->user()->name])->id;
            }
            $case->update(['lab_test' => $data['lab_test'], 'lab_sampled_on' => $data['lab_sampled_on'], 'lab_result' => $data['lab_result'] ?? $case->lab_result, 'lab_cost_minor' => $cost,
                'lab_ledger_entry_id' => $ledger, 'contagion' => $data['contagion'] ?? $case->contagion]);
            Farm::event($case->animal, 'health', "Lab {$data['lab_test']} ({$case->label()})".(! empty($data['lab_result']) ? ": {$data['lab_result']}" : ': sample sent'), $request->user()->name);
        });

        return back()->with('success', 'Lab investigation saved.');
    }

    /** Not recovered: extend treatment and reassess on a date (lab and mortality are separate actions). */
    public function extend(Request $request, HealthCase $case): RedirectResponse
    {
        $this->vet($request);
        abort_unless(in_array($case->status, HealthCase::ACTIVE, true), 422, 'This case is closed.');
        $data = $request->validate(['review_on' => ['required', 'date', 'after:today'], 'notes' => ['required', 'string', 'max:500']], ['notes.required' => 'Say what changes (treatment, dose, protocol).']);
        $case->update(['review_on' => $data['review_on'], 'status' => 'under_treatment', 'notes' => trim(($case->notes ? "{$case->notes}\n" : '').now()->format('d/m').": not recovered, {$data['notes']}")]);
        Farm::event($case->animal, 'health', "Not recovered ({$case->label()}): {$data['notes']}. Reassess ".date('j M', strtotime($data['review_on'])), $request->user()->name);

        return back()->with('success', "{$case->label()} extended. Reassess on ".date('j M', strtotime($data['review_on'])).'.');
    }

    /** Recovered: the vet clears the goat for suitable housing; labour hours complete the case cost. */
    public function clear(Request $request, HealthCase $case): RedirectResponse
    {
        $this->vet($request);
        abort_unless(in_array($case->status, HealthCase::ACTIVE, true), 422, 'This case is closed.');
        $data = $request->validate(['to_pen_id' => ['nullable', Rule::exists('pens', 'id')->where('kind', 'pen')], 'labour_hours' => ['nullable', 'numeric', 'between:0,1000'], 'notes' => ['nullable', 'string', 'max:500']]);
        DB::transaction(function () use ($case, $data, $request) {
            $case->update(['status' => 'recovered', 'resolved_on' => now()->toDateString(), 'isolated' => false, 'cleared_by' => $request->user()->name, 'cleared_to_pen_id' => $data['to_pen_id'] ?? null,
                'labour_hours' => $data['labour_hours'] ?? $case->labour_hours, 'notes' => ! empty($data['notes']) ? trim(($case->notes ? "{$case->notes}\n" : '').$data['notes']) : $case->notes]);
            if (! empty($data['to_pen_id'])) {
                Movements::transfer($case->animal->fresh(), Pen::findOrFail($data['to_pen_id']), $request->user(), ['reason' => "Cleared after {$case->label()}"]);
            }
            Checkups::syncHealth($case->animal->fresh());
            Task::where('animal_id', $case->animal_id)->where('title', 'like', "EMERGENCY {$case->label()}%")->whereIn('status', Task::OPEN)->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $request->user()->name]);
            Farm::event($case->animal, 'health', "Recovered and cleared by the vet ({$case->label()})", $request->user()->name);
        });
        $left = Biosecurity::restrictedPens()[$case->restricted_pen_id] ?? null;

        return back()->with('success', "{$case->label()} recovered and cleared.".($case->restricted_pen_id ? ($left ? " The PEN stays restricted for {$left}." : ' The PEN restriction is lifted.') : ''));
    }

    /** Close the outbreak with its report: every case resolved. */
    public function closeOutbreak(Request $request, Outbreak $outbreak): RedirectResponse
    {
        $this->vet($request);
        abort_unless($outbreak->status === 'open', 422, 'Already closed.');
        abort_if($outbreak->cases()->whereIn('status', HealthCase::ACTIVE)->exists(), 422, 'Resolve every case of the outbreak first.');
        $data = $request->validate(['report' => ['required', 'string', 'max:5000']], ['report.required' => 'Write the outbreak report: source, spread, control measures and lessons.']);
        $outbreak->update(['status' => 'closed', 'closed_on' => now()->toDateString(), 'report' => $data['report'], 'closed_by' => $request->user()->name]);

        return back()->with('success', "{$outbreak->code} closed with its report.");
    }

    public function settings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_disease_control') || $request->user()->passes('clinical'), 403);
        $data = $request->validate(['labour_rate' => ['required', 'numeric', 'min:0', 'max:100000']], [], ['labour_rate' => 'labour rate']);
        Organization::whereKey(Tenant::current())->update(['labour_rate_minor' => (int) round($data['labour_rate'] * 100)]);

        return back()->with('success', 'Labour rate saved.');
    }
}
