<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Department;
use App\Models\LedgerEntry;
use App\Models\Medicine;
use App\Models\Organization;
use App\Models\ParasiteSample;
use App\Models\Pen;
use App\Models\Task;
use App\Models\Treatment;
use App\Support\Farm;
use App\Support\MedicineStock;
use App\Support\Parasites;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** D-200: journey K Parasite Surveillance-to-Control (V3 flow 34), in the Veterinary app. */
class ParasiteController extends Controller
{
    public function index(Request $request): Response
    {
        $samples = ParasiteSample::with(['animal', 'pen.shed', 'retestOf'])->where(fn ($q) => $q->where('sampled_on', '>=', now()->subDays(180)->toDateString())->orWhereNull('decision'))
            ->orderByRaw('case when decision is null then 0 else 1 end')->orderByDesc('sampled_on')->orderByDesc('id')->limit(300)->get();
        $retested = ParasiteSample::whereNotNull('retest_of_id')->pluck('retest_of_id')->flip();
        $org = Tenant::org();

        return Inertia::render('Parasites', [
            'samples' => $samples->map(fn (ParasiteSample $s) => [
                'id' => $s->id, 'no' => $s->label(), 'goat' => $s->animal ? ['id' => $s->animal_id, 'number' => $s->animal->farm_number] : null, 'pen' => $s->pen?->fullName(), 'penId' => $s->pen_id,
                'on' => $s->sampled_on->format('j M Y'), 'reason' => $s->reason, 'epg' => $s->epg, 'famacha' => $s->famacha, 'bcs' => $s->bcs, 'lab' => $s->lab, 'cost' => $s->cost_minor,
                'retestOf' => $s->retestOf?->label(), 'preEpg' => $s->retestOf?->epg, 'reduction' => $s->reduction_pct, 'resistance' => $s->resistance_flag, 'aboveGuide' => Parasites::aboveGuide($s),
                'decision' => $s->decision, 'decisionNote' => $s->decision_note, 'decidedBy' => $s->decided_by, 'treated' => $s->treated, 'retestDue' => $s->retest_due_on?->format('j M Y'),
                'retestOverdue' => $s->decision === 'treat' && $s->retest_due_on && $s->retest_due_on->lte(now()) && ! isset($retested[$s->id]), 'retested' => isset($retested[$s->id]), 'by' => $s->recorded_by,
            ]),
            'kpi' => Parasites::kpis(),
            'goats' => Animal::active()->orderBy('farm_number')->get(['id', 'farm_number', 'pen_id']),
            'pens' => Pen::with('shed')->where('kind', 'pen')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName()])->sortBy('name')->values(),
            'dewormers' => Medicine::where('category', 'dewormer')->orderBy('name')->get(['id', 'name', 'unit'])->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'unit' => $m->unit, 'stock' => (float) (MedicineStock::quantities()[$m->id] ?? 0)]),
            'guide' => ['epg' => $org?->fec_treat_epg, 'famacha' => $org?->famacha_treat],
            'options' => ['reasons' => ParasiteSample::REASONS, 'decisions' => ParasiteSample::DECISIONS, 'routes' => Treatment::ROUTES],
            'isVet' => $request->user()->passes('clinical'),
        ]);
    }

    /** Record a sample: FEC from the lab and / or a FAMACHA and body score on the farm. A retest scores the reduction. */
    public function sample(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'animal_id' => ['nullable', 'required_without:pen_id', 'exists:animals,id'], 'pen_id' => ['nullable', 'exists:pens,id'], 'sampled_on' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', Rule::in(array_keys(ParasiteSample::REASONS))], 'epg' => ['nullable', 'required_without:famacha', 'integer', 'between:0,200000'],
            'famacha' => ['nullable', 'integer', 'between:1,5'], 'bcs' => ['nullable', 'integer', 'between:1,5'], 'lab' => ['nullable', 'string', 'max:120'],
            'cost' => ['nullable', 'numeric', 'min:0'], 'retest_of_id' => ['nullable', 'required_if:reason,retest', 'exists:parasite_samples,id'], 'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['animal_id' => 'goat or pen', 'epg' => 'egg count (EPG)', 'retest_of_id' => 'sample being retested']);
        $user = $request->user();
        $s = DB::transaction(function () use ($data, $user) {
            DB::statement('LOCK TABLE parasite_samples IN SHARE ROW EXCLUSIVE MODE');
            $minor = ! empty($data['cost']) ? (int) round($data['cost'] * 100) : null;
            $ledger = $minor ? LedgerEntry::create(['direction' => 'out', 'category' => 'Veterinary', 'amount' => $minor / 100, 'occurred_on' => $data['sampled_on'], 'counterparty' => $data['lab'] ?? 'Laboratory',
                'description' => 'Faecal egg count', 'recorded_by' => $user->name, 'payment_status' => 'pending']) : null;
            $s = ParasiteSample::create(collect($data)->except('cost')->all() + ['sample_no' => (int) ParasiteSample::max('sample_no') + 1, 'cost_minor' => $minor, 'ledger_entry_id' => $ledger?->id, 'recorded_by' => $user->name]);
            if ($s->retest_of_id) {
                Parasites::scoreRetest($s);
            }
            Parasites::welfareFlag($s);
            if ($s->animal) {
                Farm::event($s->animal, 'health', "Parasite sample {$s->label()}".($s->epg !== null ? ": {$s->epg} EPG" : '').($s->famacha ? ", FAMACHA {$s->famacha}" : ''), $user->name);
            }

            return $s->fresh();
        });
        $msg = $s->retest_of_id ? "Retest {$s->label()}: ".($s->reduction_pct !== null ? "{$s->reduction_pct}% reduction" : 'saved').($s->resistance_flag ? '. Possible resistance: the vet is alerted.' : '.') : "Sample {$s->label()} saved for the vet's decision.";

        return back()->with('success', $msg);
    }

    /**
     * The vet's decision. Treat: only the goats the vet selects, the product from the store, the vet's own meat and
     * milk clear dates (or "no withdrawal" ticked) and the retest date. Monitor / no treatment: a note.
     */
    public function decide(Request $request, ParasiteSample $sample): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'The vet decides treatment, withdrawal and retesting.');
        abort_if($sample->decision !== null, 422, 'This sample was already decided.');
        $data = $request->validate([
            'decision' => ['required', Rule::in(array_keys(ParasiteSample::DECISIONS))], 'note' => ['nullable', 'string', 'max:500'],
            'animal_ids' => ['nullable', 'required_if:decision,treat', 'array', 'min:1'], 'animal_ids.*' => ['uuid', 'distinct', 'exists:animals,id'],
            'medicine_id' => ['nullable', 'required_if:decision,treat', 'exists:medicines,id'], 'qty_per_goat' => ['nullable', 'required_if:decision,treat', 'numeric', 'gt:0', 'max:1000'],
            'route' => ['nullable', 'required_if:decision,treat', Rule::in(array_keys(Treatment::ROUTES))],
            'meat_clear_on' => ['nullable', 'date', 'after_or_equal:today'], 'milk_clear_on' => ['nullable', 'date', 'after_or_equal:today'], 'no_withdrawal' => ['boolean'],
            'retest_due_on' => ['nullable', 'required_if:decision,treat', 'date', 'after:today'],
        ], [], ['animal_ids' => 'goats to treat', 'medicine_id' => 'dewormer', 'qty_per_goat' => 'dose per goat', 'retest_due_on' => 'retest date']);
        $user = $request->user();
        if ($data['decision'] === 'treat' && empty($data['no_withdrawal']) && empty($data['meat_clear_on']) && empty($data['milk_clear_on'])) {
            throw ValidationException::withMessages(['meat_clear_on' => 'Enter the meat and milk clear dates, or tick that this dewormer has no withdrawal.']);
        }
        $goats = $data['decision'] === 'treat' ? Animal::active()->whereIn('id', $data['animal_ids'])->get() : collect();
        if ($data['decision'] === 'treat') {
            $allowed = $sample->animal_id ? [$sample->animal_id] : Animal::active()->where('pen_id', $sample->pen_id)->pluck('id')->all();
            if ($goats->count() !== count($data['animal_ids']) || $goats->pluck('id')->diff($allowed)->isNotEmpty()) {
                throw ValidationException::withMessages(['animal_ids' => 'Treat only goats from this sample'.($sample->pen_id ? "'s pen" : '').'.']);
            }
        }
        $short = 0.0;
        DB::transaction(function () use ($sample, $data, $user, $goats, &$short) {
            $medicine = ! empty($data['medicine_id']) ? Medicine::findOrFail($data['medicine_id']) : null;
            foreach ($goats as $g) {
                $cost = $medicine?->cost_per_unit_minor ? (int) round($data['qty_per_goat'] * $medicine->cost_per_unit_minor) : null;
                Treatment::create(['animal_id' => $g->id, 'parasite_sample_id' => $sample->id, 'given_on' => now()->toDateString(), 'product' => $medicine->name, 'dose' => "{$data['qty_per_goat']} {$medicine->unit}",
                    'route' => $data['route'], 'meat_clear_on' => empty($data['no_withdrawal']) ? ($data['meat_clear_on'] ?? null) : null, 'milk_clear_on' => empty($data['no_withdrawal']) ? ($data['milk_clear_on'] ?? null) : null,
                    'cost_minor' => $cost, 'notes' => "Targeted after {$sample->label()}", 'recorded_by' => $user->name]);
                $short += MedicineStock::issue($medicine, (float) $data['qty_per_goat'], ['moved_on' => now()->toDateString(), 'reference' => "Targeted deworming {$sample->label()}", 'recorded_by' => $user->name]);
                Farm::event($g, 'health', "Targeted deworming: {$medicine->name} after {$sample->label()}".(! empty($data['meat_clear_on']) ? " · meat clear {$data['meat_clear_on']}" : ''), $user->name);
            }
            $sample->update(['decision' => $data['decision'], 'decision_note' => $data['note'] ?? null, 'decided_by' => $user->name, 'decided_at' => now(),
                'retest_due_on' => $data['retest_due_on'] ?? null, 'treated' => $goats->count()]);
            if ($data['decision'] === 'treat') {
                Task::create(['title' => mb_substr("Retest egg count: {$sample->label()} ".($sample->animal?->farm_number ?? $sample->pen?->fullName() ?? ''), 0, 190), 'type' => 'health', 'priority' => 'medium',
                    'status' => 'open', 'due_on' => $data['retest_due_on'], 'animal_id' => $sample->animal_id, 'pen_id' => $sample->pen_id, 'department_id' => Department::where('name', 'Veterinary')->value('id'),
                    'notes' => 'Faecal egg count after targeted dosing, to check the dewormer worked.']);
            }
        });
        Farm::log($user, "Parasite decision {$sample->label()}: {$data['decision']}", '/parasites');
        $warn = $short > 0 ? " Warning: {$short} short in the medicine store." : '';

        return back()->with('success', ($data['decision'] === 'treat' ? "{$goats->count()} goat(s) treated; retest due {$data['retest_due_on']}." : ParasiteSample::DECISIONS[$data['decision']].'.').$warn);
    }

    public function guide(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'The vet sets the treatment guide.');
        $data = $request->validate(['fec_treat_epg' => ['nullable', 'integer', 'between:0,100000'], 'famacha_treat' => ['nullable', 'integer', 'between:1,5']]);
        Organization::whereKey(Tenant::current())->update($data);

        return back()->with('success', 'Treatment guide saved. It only highlights samples; you decide each one.');
    }
}
