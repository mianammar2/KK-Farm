<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\BreederSelection;
use App\Models\Breed;
use App\Models\Department;
use App\Models\GrowthIntervention;
use App\Models\GrowthTarget;
use App\Models\HealthRecord;
use App\Models\Kidding;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Weaning;
use App\Models\WeightRecord;
use App\Support\Farm;
use App\Support\Growth;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-191: V3 cross-app journey B Birth-to-Breeder (flow 25). The first half lives in Breeding & Maternity (mating and
 * pregnancy, due-date alert and maternity transfer, kidding with live / stillborn per kid, colostrum, kid care, permanent
 * kid ID + RFID, weaning). This page carries the second half: weaning and regular weight tracking → growth and health
 * meet target? No: nutrition / veterinary intervention → follow-up. Yes: select replacement breeder or sale stock →
 * breeding service or sales lifecycle. Key control: no live IDs for stillborns (kidding keeps counts only), weak-born kept
 * apart from mortality. KPIs: kid survival %, weaning %, ADG, replacement selection rate.
 */
class BirthToBreederController extends Controller
{
    private function lead(Request $request): bool
    {
        return $request->user()->passes('select_breeders');
    }

    public function index(Request $request): Response
    {
        $org = Organization::findOrFail(Tenant::current());
        $from = now()->subDays(365)->toDateString();
        $young = Animal::with(['breed', 'pen.shed', 'dam'])->active()->whereNotNull('birth_date')->where('birth_date', '>=', $from)->orderBy('birth_date')->get();
        $weights = Growth::latest($young->modelKeys());
        $open = GrowthIntervention::where('status', 'open')->get()->keyBy('animal_id');

        $born = Animal::where('origin', 'born_on_farm')->whereNotNull('birth_date')->where('birth_date', '>=', $from)->get(['id', 'birth_date', 'birth_outcome', 'status', 'exit_reason', 'exit_date', 'birth_weight_kg']);
        $diedPre = $born->filter(fn ($k) => $k->exit_reason === 'died' && $k->exit_date && $k->birth_date->diffInDays($k->exit_date) <= $org->weaning_age_days)->count();
        $weanings = Weaning::with('kid')->where('status', 'completed')->whereIn('kid_id', $born->modelKeys())->get();
        $ofAge = $born->filter(fn ($k) => $k->birth_date->diffInDays(now()) >= $org->weaning_age_days + 14);
        $adgs = $weanings->filter(fn ($w) => $w->weight_kg && $w->kid->birth_date)->map(fn ($w) => ($w->weight_kg - ((float) $w->kid->birth_weight_kg ?: Growth::plan($w->kid->breed_id)[0] ?? 3.0)) / max(1, $w->kid->birth_date->diffInDays($w->weaned_on)) * 1000);
        $kiddings = Kidding::where('kidded_on', '>=', $from)->get(['born_alive', 'stillborn']);
        $decisions = BreederSelection::where('decided_on', '>=', $from)->orderByDesc('id')->get()->unique('animal_id');
        $latestDecision = BreederSelection::orderByDesc('id')->get()->unique('animal_id')->keyBy('animal_id');

        $candidates = Animal::with(['breed', 'pen.shed'])->active()->whereNotNull('birth_date')
            ->where('birth_date', '<=', now()->subDays($org->selection_age_days)->toDateString())->where('birth_date', '>=', now()->subDays(720)->toDateString())
            ->get()->reject(fn ($a) => in_array($latestDecision->get($a->id)?->decision, ['replacement', 'sale'], true));
        $cw = Growth::latest($candidates->modelKeys());

        return Inertia::render('BirthToBreeder', [
            'kpi' => [
                'born' => (int) $kiddings->sum('born_alive'), 'stillborn' => (int) $kiddings->sum('stillborn'), 'weak' => $born->where('birth_outcome', 'BAW')->count(),
                'diedPreWeaning' => $diedPre, 'survival' => $born->count() ? round(($born->count() - $diedPre) / $born->count() * 100, 1) : null,
                'weaning' => $ofAge->count() ? round($weanings->filter(fn ($w) => $ofAge->contains('id', $w->kid_id))->count() / $ofAge->count() * 100, 1) : null,
                'adg' => $adgs->count() ? (int) round($adgs->avg()) : null, 'weaned' => $weanings->count(),
                'selected' => $decisions->where('decision', 'replacement')->count(), 'sale' => $decisions->where('decision', 'sale')->count(),
                'selectionRate' => $decisions->whereIn('decision', ['replacement', 'sale'])->count() ? round($decisions->where('decision', 'replacement')->count() / $decisions->whereIn('decision', ['replacement', 'sale'])->count() * 100) : null,
            ],
            'growth' => $young->map(function (Animal $a) use ($weights, $open, $org) {
                $s = Growth::status($a, $weights[$a->id] ?? null, $org->growth_alert_pct);
                $i = $open[$a->id] ?? null;

                return ['id' => $a->id, 'number' => $a->farm_number, 'sex' => $a->sex, 'breed' => $a->breed?->name, 'pen' => $a->pen?->fullName(), 'dam' => $a->dam?->farm_number,
                    'weak' => $a->birth_outcome === 'BAW', 'weaned' => $a->kid_stage === 'weaned' || $a->kid_stage === 'breeding_selected' || $a->kid_stage === 'sale_selected'] + $s
                    + ['intervention' => $i ? ['id' => $i->id, 'kind' => $i->kind, 'due' => $i->follow_up_on->format('d/m'), 'overdue' => $i->follow_up_on->lte(today())] : null];
            })->values(),
            'interventions' => GrowthIntervention::with('animal')->where(fn ($q) => $q->where('status', 'open')->orWhere('closed_on', '>=', now()->subDays(90)->toDateString()))->orderByRaw("status = 'open' desc")->orderByDesc('id')->limit(100)->get()
                ->map(fn (GrowthIntervention $i) => ['id' => $i->id, 'goatId' => $i->animal_id, 'number' => $i->animal?->farm_number, 'kind' => $i->kind, 'reason' => $i->reason, 'action' => $i->action,
                    'weight' => $i->weight_kg, 'target' => $i->target_kg, 'opened' => $i->opened_on->format('d/m'), 'due' => $i->follow_up_on->format('d/m/Y'), 'status' => $i->status, 'result' => $i->result_kg,
                    'by' => $i->opened_by, 'closedBy' => $i->closed_by]),
            'candidates' => $candidates->map(fn (Animal $a) => [
                'id' => $a->id, 'number' => $a->farm_number, 'sex' => $a->sex, 'breed' => $a->breed?->name, 'pen' => $a->pen?->fullName(), 'purpose' => $a->animal_purpose,
                'checks' => $c = Growth::eligibility($a, $cw[$a->id] ?? null, $org->selection_age_days, $org->growth_alert_pct),
                'eligible' => collect($c)->every(fn ($x) => $x['ok']), 'last' => ($d = $latestDecision->get($a->id)) ? BreederSelection::DECISIONS[$d->decision].' · '.$d->decided_on->format('d/m') : null,
            ])->sortByDesc('eligible')->values(),
            'selections' => BreederSelection::with('animal')->orderByDesc('id')->limit(50)->get()->map(fn (BreederSelection $s) => [
                'id' => $s->id, 'goatId' => $s->animal_id, 'number' => $s->animal?->farm_number, 'decision' => $s->decision, 'reason' => $s->reason, 'override' => $s->override,
                'failed' => collect($s->checks)->reject(fn ($c) => $c['ok'])->pluck('label')->values(), 'by' => $s->decided_by, 'on' => $s->decided_on->format('d/m/Y'),
            ]),
            'plan' => [
                'farm' => GrowthTarget::whereNull('breed_id')->orderBy('age_days')->get(['age_days', 'target_kg']),
                'breeds' => GrowthTarget::whereNotNull('breed_id')->orderBy('age_days')->get(['breed_id', 'age_days', 'target_kg'])->groupBy('breed_id'),
                'defaults' => collect(Growth::DEFAULTS)->map(fn ($kg, $age) => ['age_days' => $age, 'target_kg' => $kg])->values(),
            ],
            'breeds' => Breed::orderBy('name')->get(['id', 'name']),
            'settings' => ['selectionAge' => $org->selection_age_days, 'alertPct' => $org->growth_alert_pct, 'weaningAge' => $org->weaning_age_days],
            'options' => ['kinds' => GrowthIntervention::KINDS, 'status' => GrowthIntervention::STATUS, 'decisions' => BreederSelection::DECISIONS],
            'isLead' => $this->lead($request),
        ]);
    }

    /** Growth or health below target → nutrition or veterinary intervention, with a follow-up date. */
    public function intervene(Request $request, Animal $animal): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(GrowthIntervention::KINDS))], 'action' => ['required', 'string', 'max:500'],
            'follow_up_on' => ['required', 'date', 'after:today']], ['action.required' => 'Say what will be done (ration change, deworming check…).']);
        abort_if(GrowthIntervention::where('animal_id', $animal->id)->where('status', 'open')->exists(), 422, "{$animal->farm_number} already has an open intervention. Record its follow-up first.");
        $org = Organization::findOrFail(Tenant::current());
        $s = Growth::status($animal, Growth::latest([$animal->id])[$animal->id] ?? null, $org->growth_alert_pct);
        $by = $request->user()->name;
        DB::transaction(function () use ($animal, $data, $s, $by) {
            $reason = $s['pct'] !== null ? "{$s['weight']} KG at {$s['age']} days = {$s['pct']}% of the {$s['target']} KG target" : 'No recent weight';
            GrowthIntervention::create(['animal_id' => $animal->id, 'kind' => $data['kind'], 'reason' => $reason, 'action' => $data['action'], 'weight_kg' => $s['weight'], 'target_kg' => $s['target'],
                'opened_on' => now()->toDateString(), 'follow_up_on' => $data['follow_up_on'], 'status' => 'open', 'opened_by' => $by]);
            if ($data['kind'] === 'veterinary') {
                HealthRecord::create(['animal_id' => $animal->id, 'kind' => 'observation', 'occurred_on' => now()->toDateString(), 'title' => 'Poor growth: veterinary review',
                    'notes' => "{$reason}. {$data['action']}", 'severity' => 'moderate', 'recorded_by' => $by, 'recorded_role' => 'Growth', 'status' => 'open']);
            }
            Task::create(['title' => mb_substr(GrowthIntervention::KINDS[$data['kind']].": {$animal->farm_number}", 0, 190), 'type' => $data['kind'] === 'veterinary' ? 'health' : 'feeding', 'priority' => 'medium',
                'status' => 'open', 'due_on' => now()->toDateString(), 'animal_id' => $animal->id, 'pen_id' => $animal->pen_id,
                'department_id' => Department::where('name', $data['kind'] === 'veterinary' ? 'Veterinary' : 'Feeding')->value('id'), 'notes' => mb_substr("{$reason}. {$data['action']}", 0, 500)]);
            Task::create(['title' => mb_substr("Growth follow-up weighing: {$animal->farm_number}", 0, 190), 'type' => 'other', 'priority' => 'medium', 'status' => 'open', 'due_on' => $data['follow_up_on'],
                'animal_id' => $animal->id, 'pen_id' => $animal->pen_id, 'department_id' => Department::where('name', 'Caretaking')->value('id'), 'notes' => 'Weigh and record the follow-up on Birth-to-Breeder.']);
            Farm::event($animal, 'weight', GrowthIntervention::KINDS[$data['kind']].": {$reason}", $by);
        });

        return back()->with('success', "Intervention started for {$animal->farm_number}. Follow-up on ".date('j M', strtotime($data['follow_up_on'])).'.');
    }

    /** Follow-up: weigh again; back on track (≥ alert %) closes it, otherwise it is not improved (start another). */
    public function followUp(Request $request, GrowthIntervention $intervention): RedirectResponse
    {
        abort_unless($intervention->status === 'open', 422, 'This intervention is closed.');
        $data = $request->validate(['weight_kg' => ['required', 'numeric', 'between:0.5,150'], 'body_score' => ['nullable', 'integer', 'between:1,5']]);
        $animal = $intervention->animal;
        $org = Organization::findOrFail(Tenant::current());
        $by = $request->user()->name;
        $status = DB::transaction(function () use ($animal, $data, $intervention, $org, $by) {
            $w = WeightRecord::create(['animal_id' => $animal->id, 'measured_on' => now()->toDateString(), 'weight_kg' => $data['weight_kg'], 'body_score' => $data['body_score'] ?? null, 'context' => 'follow_up', 'measured_by' => $by]);
            $s = Growth::status($animal, $w, $org->growth_alert_pct);
            $status = $s['below'] ? 'not_improved' : 'improved';
            $intervention->update(['status' => $status, 'result_kg' => $data['weight_kg'], 'closed_by' => $by, 'closed_on' => now()->toDateString()]);
            Task::where('animal_id', $animal->id)->where('title', "Growth follow-up weighing: {$animal->farm_number}")->whereIn('status', Task::OPEN)->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $by]);
            Farm::event($animal, 'weight', 'Growth follow-up: '.GrowthIntervention::STATUS[$status]." ({$data['weight_kg']} KG".($s['pct'] !== null ? ", {$s['pct']}% of target" : '').')', $by);

            return $status;
        });

        return back()->with('success', $status === 'improved' ? "{$animal->farm_number} is back on track." : "{$animal->farm_number} has not improved. Start another intervention (veterinary if nutrition did not help).");
    }

    /** Select replacement breeder or sale stock. The system recommends from BR-005 checks; a person decides (override needs a lead and a reason). */
    public function select(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($this->lead($request), 403, 'The owner, manager or vet selects breeders.');
        $data = $request->validate(['decision' => ['required', Rule::in(array_keys(BreederSelection::DECISIONS))], 'reason' => ['nullable', 'string', 'max:500']]);
        $org = Organization::findOrFail(Tenant::current());
        $checks = Growth::eligibility($animal, Growth::latest([$animal->id])[$animal->id] ?? null, $org->selection_age_days, $org->growth_alert_pct);
        $failed = collect($checks)->reject(fn ($c) => $c['ok']);
        $override = $data['decision'] === 'replacement' && $failed->isNotEmpty();
        if ($override && empty($data['reason'])) {
            throw ValidationException::withMessages(['reason' => 'Not all breeding checks pass ('.$failed->pluck('label')->implode('; ').'). Give the reason for selecting anyway.']);
        }
        $by = $request->user()->name;
        DB::transaction(function () use ($animal, $data, $checks, $override, $by) {
            BreederSelection::create(['animal_id' => $animal->id, 'decision' => $data['decision'], 'reason' => $data['reason'] ?? null, 'checks' => $checks, 'override' => $override, 'decided_by' => $by, 'decided_on' => now()->toDateString()]);
            if ($data['decision'] !== 'keep') {
                $animal->update(['animal_purpose' => $data['decision'] === 'replacement' ? 'replacement' : 'sale',
                    'kid_stage' => $animal->kid_stage !== null || $animal->birth_date?->gt(now()->subYear()) ? ($data['decision'] === 'replacement' ? 'breeding_selected' : 'sale_selected') : $animal->kid_stage]);
            }
            Farm::event($animal, 'breeding', 'Selection: '.BreederSelection::DECISIONS[$data['decision']].($override ? ' (override)' : '').(! empty($data['reason']) ? ": {$data['reason']}" : ''), $by);
        });

        return back()->with('success', "{$animal->farm_number}: ".BreederSelection::DECISIONS[$data['decision']].'.');
    }

    /** Growth plan: target KG by age, farm-wide or for one breed. */
    public function plan(Request $request): RedirectResponse
    {
        abort_unless($this->lead($request), 403);
        $data = $request->validate(['breed_id' => ['nullable', 'exists:breeds,id'], 'points' => ['present', 'array', 'max:20'], 'points.*.age_days' => ['required', 'integer', 'between:0,1095', 'distinct'],
            'points.*.target_kg' => ['required', 'numeric', 'between:0.5,150']]);
        $points = collect($data['points'])->sortBy('age_days')->values();
        foreach ($points as $i => $p) {
            if ($i > 0 && $p['target_kg'] < $points[$i - 1]['target_kg']) {
                throw ValidationException::withMessages(['points' => 'Target weights must not go down as the goat gets older.']);
            }
        }
        DB::transaction(function () use ($data, $points) {
            GrowthTarget::where('breed_id', $data['breed_id'] ?? null)->delete();
            foreach ($points as $p) {
                GrowthTarget::create(['breed_id' => $data['breed_id'] ?? null, 'age_days' => $p['age_days'], 'target_kg' => $p['target_kg']]);
            }
        });
        Growth::flush();

        return back()->with('success', 'Growth plan saved.');
    }

    public function settings(Request $request): RedirectResponse
    {
        abort_unless($this->lead($request), 403);
        $data = $request->validate(['selection_age_days' => ['required', 'integer', 'between:60,720'], 'growth_alert_pct' => ['required', 'integer', 'between:50,99']],
            [], ['selection_age_days' => 'selection age', 'growth_alert_pct' => 'growth alert']);
        Organization::whereKey(Tenant::current())->update($data);

        return back()->with('success', 'Selection settings saved.');
    }
}
