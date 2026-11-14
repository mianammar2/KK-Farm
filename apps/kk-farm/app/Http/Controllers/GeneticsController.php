<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\BreederSelection;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Task;
use App\Support\Farm;
use App\Support\Genetics;
use App\Support\Growth;
use App\Support\Movements;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-201: journey L Genetic Selection-to-Replacement (V3 flow 35), in Breeding & Genetics: sire and dam progeny
 * performance → replacement review (own growth, parents' measured merit, vet and welfare checks; pedigree shown as
 * information) → decision with the planned buck and its inbreeding, and a move to the replacement pen.
 */
class GeneticsController extends Controller
{
    private function lead(Request $request): bool
    {
        return in_array($request->user()->role, ['ceo', 'manager', 'vet'], true);
    }

    public function index(Request $request): Response
    {
        Genetics::flush();
        $org = Organization::findOrFail(Tenant::current());
        $prog = Genetics::progeny();
        $parents = $prog['parents']->keyBy('id');
        $latest = BreederSelection::orderByDesc('id')->get()->unique('animal_id')->keyBy('animal_id');
        $candidates = Animal::with(['breed', 'pen.shed'])->active()->whereNotNull('birth_date')
            ->where('birth_date', '<=', now()->subDays($org->selection_age_days)->toDateString())->where('birth_date', '>=', now()->subDays(720)->toDateString())
            ->get()->reject(fn ($a) => in_array($latest->get($a->id)?->decision, ['replacement', 'sale'], true));
        $cw = Growth::latest($candidates->modelKeys());
        $bucks = Animal::active()->where('sex', 'male')->whereIn('animal_purpose', ['breeding', 'replacement'])->orderBy('farm_number')->get(['id', 'farm_number', 'sire_id', 'dam_id']);
        $pairing = null;
        if ($request->filled(['dam', 'sire'])) {
            $pairing = ['dam' => $request->string('dam'), 'sire' => $request->string('sire'), 'coi' => Genetics::coi($request->string('sire'), $request->string('dam'))];
        }

        return Inertia::render('Genetics', [
            'parents' => $prog['parents'], 'herd' => $prog['herd'], 'kpi' => Genetics::kpis(), 'limit' => Genetics::limit(), 'pairing' => $pairing,
            'candidates' => $candidates->map(function (Animal $a) use ($cw, $org, $parents, $bucks, $latest) {
                $checks = Growth::eligibility($a, $cw[$a->id] ?? null, $org->selection_age_days, $org->growth_alert_pct);

                return [
                    'id' => $a->id, 'number' => $a->farm_number, 'sex' => $a->sex, 'breed' => $a->breed?->name, 'pen' => $a->pen?->fullName(), 'purpose' => $a->animal_purpose,
                    'checks' => array_values(array_map(fn ($k, $c) => $c + ['key' => $k], array_keys($checks), $checks)),
                    'eligible' => collect($checks)->every(fn ($c) => $c['ok']),
                    'sire' => isset($parents[$a->sire_id]) ? ['number' => $parents[$a->sire_id]['number'], 'merit' => $parents[$a->sire_id]['merit']] : null,
                    'dam' => isset($parents[$a->dam_id]) ? ['number' => $parents[$a->dam_id]['number'], 'merit' => $parents[$a->dam_id]['merit']] : null,
                    'pedigree' => Genetics::pedigreeKnown($a), 'coi' => round(Genetics::inbreeding($a->id) * 100, 2),
                    // for a doe: bucks ranked by measured merit, with the inbreeding of their kid with her
                    'bucks' => $a->sex === 'female' ? $bucks->map(fn ($b) => ['id' => $b->id, 'number' => $b->farm_number, 'merit' => $parents[$b->id]['merit'] ?? null, 'coi' => Genetics::coi($b->id, $a->id)])
                        ->sortBy([fn ($x, $y) => ($y['merit'] ?? -1) <=> ($x['merit'] ?? -1)])->values() : [],
                    'last' => ($d = $latest->get($a->id)) ? BreederSelection::DECISIONS[$d->decision].' · '.$d->decided_on->format('d/m') : null,
                ];
            })->sortByDesc('eligible')->values(),
            'selections' => BreederSelection::with(['animal', 'plannedSire'])->orderByDesc('id')->limit(40)->get()->map(fn (BreederSelection $s) => [
                'id' => $s->id, 'goatId' => $s->animal_id, 'number' => $s->animal?->farm_number, 'decision' => $s->decision, 'reason' => $s->reason, 'override' => $s->override,
                'sire' => $s->plannedSire?->farm_number, 'coi' => $s->planned_coi_pct, 'by' => $s->decided_by, 'on' => $s->decided_on->format('d/m/Y'),
            ]),
            'bucks' => $bucks->map(fn ($b) => ['id' => $b->id, 'number' => $b->farm_number]),
            'does' => Animal::active()->where('sex', 'female')->orderBy('farm_number')->get(['id', 'farm_number'])->map(fn ($d) => ['id' => $d->id, 'number' => $d->farm_number]),
            'pens' => Pen::with('shed')->where('kind', 'pen')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName()])->sortBy('name')->values(),
            'options' => ['decisions' => BreederSelection::DECISIONS], 'isLead' => $this->lead($request),
        ]);
    }

    /**
     * Replacement decision. Failed growth, vet or welfare checks, or a planned buck above the inbreeding limit, need a
     * reason (override, recorded). The doe gets her planned buck as a breeding task; the goat may move to its pen.
     */
    public function decide(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($this->lead($request), 403, 'The owner, manager or vet decides replacements.');
        abort_unless($animal->status === 'active', 422, 'This goat is no longer on the farm.');
        Genetics::flush();
        $data = $request->validate([
            'decision' => ['required', Rule::in(array_keys(BreederSelection::DECISIONS))], 'reason' => ['nullable', 'string', 'max:500'],
            'planned_sire_id' => ['nullable', 'exists:animals,id', \App\Support\Designations::sireRule()], 'pen_id' => ['nullable', 'exists:pens,id'],
        ]);
        $org = Organization::findOrFail(Tenant::current());
        $checks = Growth::eligibility($animal, Growth::latest([$animal->id])[$animal->id] ?? null, $org->selection_age_days, $org->growth_alert_pct);
        $failed = collect($checks)->reject(fn ($c) => $c['ok'])->pluck('label');
        $sire = ! empty($data['planned_sire_id']) && $data['decision'] === 'replacement' ? Animal::findOrFail($data['planned_sire_id']) : null;
        if ($sire && ($sire->sex !== 'male' || $animal->sex !== 'female')) {
            throw ValidationException::withMessages(['planned_sire_id' => 'A planned buck is for a replacement doe.']);
        }
        $coi = $sire ? Genetics::coi($sire->id, $animal->id) : null;
        if ($coi !== null && $coi > Genetics::limit()) {
            $failed->push("Inbreeding {$coi}% with {$sire->farm_number} (limit ".Genetics::limit().'%)');
        }
        $override = $data['decision'] === 'replacement' && $failed->isNotEmpty();
        if ($override && empty($data['reason'])) {
            throw ValidationException::withMessages(['reason' => 'Not all checks pass ('.$failed->implode('; ').'). Give the reason for selecting anyway.']);
        }
        $parents = Genetics::progeny()['parents']->keyBy('id');
        $merit = ['sire' => $parents[$animal->sire_id]['merit'] ?? null, 'dam' => $parents[$animal->dam_id]['merit'] ?? null, 'pedigree' => Genetics::pedigreeKnown($animal), 'growth' => $checks['growth']['label']];
        $by = $request->user();
        DB::transaction(function () use ($animal, $data, $checks, $override, $by, $sire, $coi, $merit) {
            BreederSelection::create(['animal_id' => $animal->id, 'decision' => $data['decision'], 'reason' => $data['reason'] ?? null, 'checks' => $checks, 'override' => $override, 'decided_by' => $by->name,
                'decided_on' => now()->toDateString(), 'planned_sire_id' => $sire?->id, 'planned_coi_pct' => $coi, 'pen_id' => $data['pen_id'] ?? null, 'merit' => $merit]);
            if ($data['decision'] !== 'keep') {
                $animal->update(['animal_purpose' => $data['decision'] === 'replacement' ? 'replacement' : 'sale',
                    'kid_stage' => $animal->kid_stage !== null || $animal->birth_date?->gt(now()->subYear()) ? ($data['decision'] === 'replacement' ? 'breeding_selected' : 'sale_selected') : $animal->kid_stage]);
            }
            if (! empty($data['pen_id'])) {
                Movements::transfer($animal->fresh(), Pen::findOrFail($data['pen_id']), $by, ['reason' => BreederSelection::DECISIONS[$data['decision']]]);
            }
            if ($sire) {
                Task::create(['title' => mb_substr("Breeding plan: {$animal->farm_number} with {$sire->farm_number}", 0, 190), 'type' => 'breeding', 'priority' => 'medium', 'status' => 'open',
                    'due_on' => now()->addDays(14)->toDateString(), 'animal_id' => $animal->id, 'department_id' => Department::where('name', 'Breeding')->value('id'),
                    'notes' => "Planned buck {$sire->farm_number}; kid inbreeding {$coi}%."]);
            }
            Farm::event($animal, 'breeding', 'Replacement review: '.BreederSelection::DECISIONS[$data['decision']].($sire ? " · planned buck {$sire->farm_number} (COI {$coi}%)" : '').($override ? ' (override)' : ''), $by->name);
        });

        return back()->with('success', "{$animal->farm_number}: ".BreederSelection::DECISIONS[$data['decision']].'.');
    }

    public function settings(Request $request): RedirectResponse
    {
        abort_unless($this->lead($request), 403);
        $data = $request->validate(['inbreeding_limit_pct' => ['required', 'numeric', 'between:0,50']], [], ['inbreeding_limit_pct' => 'inbreeding limit']);
        Organization::whereKey(Tenant::current())->update($data);

        return back()->with('success', "Inbreeding alert above {$data['inbreeding_limit_pct']}%.");
    }
}
