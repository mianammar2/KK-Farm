<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\HealthCase;
use App\Models\LedgerEntry;
use App\Models\Pen;
use App\Models\ReproLossCase;
use App\Models\Task;
use App\Models\User;
use App\Support\Farm;
use App\Support\Movements;
use App\Support\ReproLoss;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** D-206: journey Q Reproductive Loss Investigation (V3 flow 40), in Maternity & Kid Care. */
class ReproLossController extends Controller
{
    public function index(Request $request): Response
    {
        $cases = ReproLossCase::with(['dam', 'sire', 'pen.shed'])->where(fn ($q) => $q->where('status', '!=', 'closed')->orWhere('closed_at', '>=', now()->subDays(180)))
            ->orderByRaw("case status when 'open' then 0 when 'contained' then 1 when 'sampled' then 2 when 'diagnosed' then 3 else 4 end")->orderByDesc('occurred_on')->get();

        return Inertia::render('ReproLoss', [
            'cases' => $cases->map(fn (ReproLossCase $c) => [
                'id' => $c->id, 'no' => $c->label(), 'damId' => $c->dam_id, 'dam' => $c->dam?->farm_number, 'sire' => $c->sire?->farm_number, 'pen' => $c->pen?->fullName(), 'type' => $c->loss_type,
                'on' => $c->occurred_on->format('d/m/Y'), 'gestation' => $c->gestation_days, 'kidsLost' => $c->kids_lost, 'status' => $c->status, 'cluster' => $c->cluster,
                'isolated' => $c->isolated_at?->format('d/m g:i A'), 'containHours' => $c->isolated_at ? round($c->created_at->diffInMinutes($c->isolated_at) / 60, 1) : null,
                'handledBy' => $c->material_handled_by, 'ppe' => $c->ppe_used, 'materialNote' => $c->material_note, 'sampled' => $c->sampled_on?->format('d/m/Y'), 'samples' => $c->samples, 'lab' => $c->lab,
                'labCost' => $c->lab_cost_minor, 'labResult' => $c->lab_result, 'zoonotic' => $c->zoonotic_suspected, 'diagnosis' => $c->diagnosis, 'diagnosedBy' => $c->diagnosed_by,
                'breeding' => $c->breeding, 'breedAfter' => $c->breed_after?->format('d/m/Y'), 'prevention' => $c->prevention, 'closedBy' => $c->closed_by,
            ]),
            'kpi' => ReproLoss::kpis(),
            'pens' => Pen::with('shed')->where('kind', 'pen')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName(), 'purpose' => $p->purpose])->sortBy('name')->values(),
            'staff' => $request->user()->passes('manage_staff') ? User::where('org_id', Tenant::current())->where('active', true)->orderBy('name')->get(['id', 'name', 'role', 'biohazard_trained']) : [],
            'options' => ['types' => ReproLossCase::TYPES, 'status' => ReproLossCase::STATUS, 'breeding' => ReproLossCase::BREEDING],
            'isVet' => $request->user()->passes('clinical'), 'canHandle' => ReproLoss::canHandle($request->user()), 'canTrain' => $request->user()->passes('manage_staff'),
        ]);
    }

    /** Isolate the doe: the clock for time to containment stops here. */
    public function contain(Request $request, ReproLossCase $case): RedirectResponse
    {
        abort_unless(ReproLoss::canHandle($request->user()), 403, 'Only the vet or trained staff isolate a pregnancy loss.'); // D-266 F-05
        abort_unless($case->status === 'open', 422, 'Already contained.');
        $data = $request->validate(['pen_id' => ['required', 'exists:pens,id']], [], ['pen_id' => 'isolation PEN']);
        DB::transaction(function () use ($case, $data, $request) {
            if ($case->dam?->status === 'active') {
                Movements::transfer($case->dam, Pen::findOrFail($data['pen_id']), $request->user(), ['care' => true, 'reason' => "Isolation after pregnancy loss {$case->label()}"]);
            }
            $case->update(['isolated_at' => now(), 'isolation_pen_id' => $data['pen_id'], 'status' => 'contained']);
        });

        return back()->with('success', 'Doe isolated. Trained staff handle the material for the vet.');
    }

    /** The aborted material, placenta and bedding: only the vet or trained staff, with gloves. */
    public function material(Request $request, ReproLossCase $case): RedirectResponse
    {
        abort_unless(ReproLoss::canHandle($request->user()), 403, 'Only the vet or staff trained for it handle aborted material. Do not touch it: call them.');
        abort_if($case->status === 'closed', 422);
        $data = $request->validate(['ppe_used' => ['accepted'], 'note' => ['required', 'string', 'max:500']], ['ppe_used.accepted' => 'Gloves and protection are required.'], ['note' => 'what was collected and how it was kept or disposed of']);
        $case->update(['material_handled_by' => $request->user()->name, 'ppe_used' => true, 'material_note' => $data['note']]);

        return back()->with('success', 'Material handling recorded.');
    }

    /** Samples to the lab (the fee is posted once). */
    public function sample(Request $request, ReproLossCase $case): RedirectResponse
    {
        abort_unless(ReproLoss::canHandle($request->user()), 403, 'Samples are taken by the vet or trained staff.');
        abort_unless(in_array($case->status, ['open', 'contained'], true), 422, 'Samples are taken before the diagnosis.');
        if (! $case->material_handled_by) {
            throw ValidationException::withMessages(['samples' => 'Record the safe handling of the material first.']);
        }
        $data = $request->validate(['samples' => ['required', 'string', 'max:200'], 'lab' => ['required', 'string', 'max:120'], 'sampled_on' => ['required', 'date', 'before_or_equal:today'], 'cost' => ['nullable', 'numeric', 'min:0']]);
        DB::transaction(function () use ($case, $data, $request) {
            $minor = ! empty($data['cost']) ? (int) round($data['cost'] * 100) : null;
            $ledger = $minor ? LedgerEntry::create(['direction' => 'out', 'category' => 'Veterinary', 'amount' => $minor / 100, 'occurred_on' => $data['sampled_on'], 'counterparty' => $data['lab'],
                'description' => "Reproductive loss diagnostics {$case->label()}", 'recorded_by' => $request->user()->name, 'payment_status' => 'pending']) : null;
            $case->update(['samples' => $data['samples'], 'lab' => $data['lab'], 'sampled_on' => $data['sampled_on'], 'lab_cost_minor' => $minor, 'lab_ledger_entry_id' => $ledger?->id,
                'status' => $case->isolated_at ? 'sampled' : $case->status]);
        });

        return back()->with('success', 'Samples sent. The vet records the diagnosis.');
    }

    /** The vet's diagnosis. A suspected zoonotic cause restricts the pen through a contagious vet case and alerts staff. */
    public function diagnose(Request $request, ReproLossCase $case): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'Only the vet diagnoses. Do not self-diagnose.');
        abort_if($case->status === 'closed', 422);
        abort_unless($case->isolated_at, 422, 'Contain first: isolate the doe.');
        $data = $request->validate(['lab_result' => ['nullable', 'string', 'max:1000'], 'diagnosis' => ['required', 'string', 'max:1000'], 'zoonotic_suspected' => ['boolean']]);
        $user = $request->user();
        DB::transaction(function () use ($case, $data, $user) {
            $zoo = (bool) ($data['zoonotic_suspected'] ?? false);
            $hc = null;
            if ($zoo && ! $case->health_case_id && $case->dam?->status === 'active') {
                DB::statement('LOCK TABLE health_cases IN SHARE ROW EXCLUSIVE MODE');
                $hc = HealthCase::create(['case_no' => (int) HealthCase::max('case_no') + 1, 'animal_id' => $case->dam_id, 'onset_on' => $case->occurred_on, 'condition' => 'Suspected infectious abortion',
                    'severity' => 'high', 'status' => 'under_treatment', 'isolated' => true, 'contagion' => 'suspected', 'restricted_pen_id' => $case->pen_id, 'vet' => $user->name, 'diagnosis' => $data['diagnosis'], 'recorded_by' => $user->name]);
                Task::create(['title' => mb_substr("Zoonotic risk: {$case->label()} ".($case->pen?->fullName() ?? ''), 0, 190), 'type' => 'biosecurity', 'priority' => 'critical', 'status' => 'open', 'due_on' => now()->toDateString(),
                    'pen_id' => $case->pen_id, 'department_id' => Department::where('name', 'Management')->value('id'),
                    'notes' => 'Suspected cause can spread to people. Trained staff only, gloves and mask; no raw milk from this pen; pregnant staff stay away; the pen stays restricted until the vet clears it.']);
            }
            $case->update(['lab_result' => $data['lab_result'] ?? $case->lab_result, 'diagnosis' => $data['diagnosis'], 'diagnosed_by' => $user->name, 'diagnosed_at' => now(), 'zoonotic_suspected' => $zoo,
                'health_case_id' => $hc?->id ?? $case->health_case_id, 'status' => 'diagnosed']);
            Farm::event($case->dam, 'health', "Pregnancy loss {$case->label()} diagnosed: {$data['diagnosis']}", $user->name);
        });

        return back()->with('success', 'Diagnosis recorded. Set the breeding decision and prevention, then close.');
    }

    /** The vet's breeding decision and prevention plan; closes the case. */
    public function close(Request $request, ReproLossCase $case): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'The vet decides future breeding.');
        abort_unless($case->status === 'diagnosed', 422, 'Diagnose first.');
        $data = $request->validate(['breeding' => ['required', Rule::in(array_keys(ReproLossCase::BREEDING))], 'breed_after' => ['nullable', 'required_if:breeding,delay', 'date', 'after:today'],
            'prevention' => ['required', 'string', 'max:1000']], [], ['prevention' => 'prevention plan']);
        $case->update($data + ['status' => 'closed', 'closed_by' => $request->user()->name, 'closed_at' => now()]);
        Farm::event($case->dam, 'breeding', "Breeding after {$case->label()}: ".ReproLossCase::BREEDING[$data['breeding']].(! empty($data['breed_after']) ? " ({$data['breed_after']})" : ''), $request->user()->name);

        return back()->with('success', "{$case->label()} closed.");
    }

    /** Mark who is trained to handle aborted material (owner or manager). */
    public function train(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_staff'), 403);
        abort_unless($user->org_id === Tenant::current(), 404);
        $data = $request->validate(['trained' => ['required', 'boolean']]);
        $user->update(['biohazard_trained' => $data['trained']]);

        return back()->with('success', "{$user->name}: ".($data['trained'] ? 'trained to handle aborted material.' : 'not trained.'));
    }
}
