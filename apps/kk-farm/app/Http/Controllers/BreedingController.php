<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Kidding;
use App\Models\Mating;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\PregnancyCheck;
use App\Models\Weaning;
use App\Models\WeightRecord;
use App\Support\Farm;
use App\Support\Maternity;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** D-075: workbook "Breeding", "Pregnancy Checks", "Kidding" and "Weaning", plus the family tree and performance. */
class BreedingController extends Controller
{
    private function org(): Organization
    {
        return Tenant::org() ?? abort(404);
    }

    public function index(Request $request): Response
    {
        \App\Support\Genetics::flush(); // D-201: fresh pedigree per request
        $org = $this->org();
        $focus = $request->query('goat') ? Animal::with(['breed', 'sire.breed', 'dam.breed', 'sire.sire', 'sire.dam', 'dam.sire', 'dam.dam'])->find($request->query('goat')) : null;
        $focus ??= Animal::with(['breed', 'sire.breed', 'dam.breed', 'sire.sire', 'sire.dam', 'dam.sire', 'dam.dam'])->active()->whereNotNull('sire_id')->orderByDesc('birth_date')->first();
        $sire = $request->query('sire') ? Animal::find($request->query('sire')) : null;
        $dam = $request->query('dam') ? Animal::find($request->query('dam')) : null;
        $today = now()->startOfDay();

        $matings = Mating::with(['dam', 'sire', 'checks'])->where(fn ($q) => $q->whereIn('status', ['open', 'pregnant', 'inconclusive'])->orWhere('updated_at', '>=', now()->subDays(60)))
            ->orderByRaw("case status when 'pregnant' then 0 when 'inconclusive' then 1 when 'open' then 2 else 3 end")->orderBy('expected_kidding_on')->get();

        // D-283: every kid born on the farm, as a spreadsheet on Kidding & Pregnancy Outcomes (the register page lists only bought and transferred goats)
        $enteredBy = \App\Models\AnimalEvent::where('type', 'origin')->orderBy('occurred_at')->get(['animal_id', 'recorded_by'])->keyBy('animal_id');
        $farmBorn = Animal::with(['breed', 'pen', 'dam', 'sire'])->whereNull('voided_at')->where('origin', 'born_on_farm')->orderByDesc('birth_date')->orderByDesc('created_at')->get()
            ->map(fn (Animal $a) => [
                'id' => $a->id, 'number' => $a->farm_number, 'born' => $a->birth_date?->format('d/m/Y'), 'bornIso' => $a->birth_date?->format('Y-m-d'),
                'dam' => $a->dam?->farm_number, 'damId' => $a->dam_id, 'sire' => $a->sire?->farm_number, 'sireId' => $a->sire_id,
                'breed' => $a->breed?->name, 'sex' => $a->sex === 'female' ? 'Female' : ($a->sex === 'male' ? 'Male' : null),
                'birthKg' => $a->birth_weight_kg !== null ? (float) $a->birth_weight_kg : null, 'code' => $a->classCode(), 'role' => \App\Support\Designations::main($a),
                'pen' => $a->pen?->name, 'status' => $a->status === 'active' ? 'On farm' : 'Left',
                'entered' => $a->created_at?->setTimezone('Asia/Karachi')->format('d/m/Y g:i A'), 'by' => $enteredBy[$a->id]->recorded_by ?? null,
            ])->values();

        return Inertia::render('Breeding', [
            'farmBorn' => $farmBorn,
            'matings' => $matings->map(fn (Mating $m) => [
                'id' => $m->id, 'status' => $m->status, 'outcome' => Mating::OUTCOMES[$m->status] ?? $m->status, 'method' => Mating::METHODS[$m->method] ?? $m->method,
                'heat' => $m->heat_observed, 'cycle' => $m->service_cycle, 'handler' => $m->handler, 'notes' => $m->notes, 'alert' => $m->alert($org->kidding_alert_days),
                'pregnancy' => $m->pregnancyStatus($org->kidding_alert_days), // D-161
                'scan' => $m->checks->last()?->ultrasound_status ?? 'NSC', 'day' => (int) $m->mated_on->diffInDays(now()), 'scanWindow' => PregnancyCheck::window((int) $m->mated_on->diffInDays(now())), // D-166
                'dam' => ['id' => $m->dam_id, 'number' => $m->dam->farm_number, 'name' => $m->dam->name, 'coat' => $m->dam->coat, 'breed' => $m->dam->breed?->name, 'stage' => $m->dam->effectiveAge()],
                'sire' => $m->sire ? ['id' => $m->sire_id, 'number' => $m->sire->farm_number, 'name' => $m->sire->name] : null,
                'mated' => $m->mated_on->format('d/m/Y'), 'matedIso' => $m->mated_on->toDateString(), 'expected' => $m->expected_kidding_on?->format('d/m/Y'), 'checkDue' => $m->check_due_on?->format('d/m/Y'),
                'days' => $m->expected_kidding_on ? (int) $today->diffInDays($m->expected_kidding_on, false) : null,
                'progress' => $m->expected_kidding_on ? min(100, max(0, (int) round($m->mated_on->diffInDays(now()) / max(1, $m->mated_on->diffInDays($m->expected_kidding_on)) * 100))) : 0,
                'active' => in_array($m->status, ['open', 'pregnant', 'inconclusive'], true),
                'checks' => $m->checks->map(fn (PregnancyCheck $c) => [
                    'id' => $c->id, 'on' => $c->checked_on->format('d/m/Y'), 'method' => PregnancyCheck::METHODS[$c->method] ?? $c->method, 'result' => PregnancyCheck::RESULTS[$c->result] ?? $c->result,
                    // D-166
                    'status' => $c->ultrasound_status, 'day' => $c->pregnancy_day, 'fetal' => PregnancyCheck::fetalLabel($c->fetuses), 'viability' => PregnancyCheck::VIABILITY[$c->viability] ?? null, 'action' => $c->action,
                    'fetuses' => $c->fetuses, 'next' => $c->next_check_on?->format('d/m/Y'), 'by' => $c->examiner, 'notes' => $c->notes,
                ])->values(),
            ]),
            'kiddings' => Kidding::with(['dam', 'kids'])->orderByDesc('kidded_on')->orderByDesc('id')->limit(30)->get()->map(fn (Kidding $k) => [
                'id' => $k->id, 'goatId' => $k->dam_id, 'dam' => $k->dam->farm_number, 'on' => $k->kidded_on->format('d/m/Y'), 'alive' => $k->born_alive, 'stillborn' => $k->stillborn,
                'total' => $k->born_alive + $k->stillborn, 'litter' => Kidding::litterType($k->born_alive + $k->stillborn), 'assisted' => Kidding::ASSISTED[$k->assisted] ?? $k->assisted,
                'attendant' => $k->attendant ?? $k->recorded_by, 'notes' => $k->notes, 'kids' => $k->kids->map(fn ($a) => ['id' => $a->id, 'number' => $a->farm_number, 'sex' => $a->sex, 'weak' => $a->birth_outcome === 'BAW'])->values(),
                // D-161
                'outcome' => $k->outcome, 'method' => $k->delivery_method, 'mother' => $k->mother_status, 'premature' => $k->premature, 'gestation' => $k->gestation_days,
                'liveMale' => $k->live_male, 'liveFemale' => $k->live_female, 'stillMale' => $k->still_male, 'stillFemale' => $k->still_female, 'weak' => $k->weak,
                'cause' => $k->abortion_cause, 'diagnosis' => $k->diagnosis, 'vet' => $k->vet_officer, 'followUp' => $k->follow_up_on?->format('d/m/Y'), 'complications' => $k->complications,
            ]),
            'weaning' => $this->weaningDue($org),
            'kpis' => Kidding::kpis(), // D-161
            'heat' => \App\Support\HeatBoard::build(), // D-175
            'kidCare' => \App\Support\KidCare::board(), // D-176
            'kidCareOptions' => ['kinds' => \App\Models\KidCareRecord::KINDS, 'sources' => \App\Models\KidCareRecord::SOURCES, 'suckling' => \App\Models\KidCareRecord::SUCKLING],
            'heatOptions' => ['intensity' => \App\Models\HeatRecord::INTENSITY, 'signs' => \App\Models\HeatRecord::SIGNS, 'cycle' => \App\Models\HeatRecord::CYCLE_DAYS],
            'weaned' => Weaning::with('kid')->where('status', 'completed')->orderByDesc('weaned_on')->limit(20)->get()->map(fn (Weaning $w) => [
                'id' => $w->id, 'kidId' => $w->kid_id, 'kid' => $w->kid->farm_number, 'on' => $w->weaned_on->format('d/m/Y'),
                'age' => $w->kid->birth_date ? (int) $w->kid->birth_date->diffInDays($w->weaned_on) : null, 'weight' => $w->weight_kg, 'method' => Weaning::METHODS[$w->method] ?? $w->method, 'by' => $w->handler,
            ]),
            'performance' => DB::table('kiddings')->join('animals', 'animals.id', '=', 'kiddings.dam_id')->groupBy('animals.id', 'animals.farm_number', 'animals.name')
                ->selectRaw('animals.id, animals.farm_number as number, animals.name, count(*) as kiddings, sum(born_alive) as alive, sum(stillborn) as stillborn, round(avg(born_alive + stillborn)::numeric, 1)::float as litter')
                ->orderByDesc('alive')->limit(20)->get(),
            'focus' => $focus ? ['id' => $focus->id, 'number' => $focus->farm_number, 'tree' => Farm::pedigree($focus)] : null,
            'pairCheck' => $sire && $dam ? ['shared' => count(Farm::sharedAncestors($sire, $dam)), 'sire' => $sire->farm_number, 'dam' => $dam->farm_number,
                'coi' => \App\Support\Genetics::coi($sire->id, $dam->id), 'limit' => \App\Support\Genetics::limit()] : null, // D-201
            'does' => Animal::active()->where('sex', 'female')->orderBy('farm_number')->get(['id', 'farm_number', 'name']),
            'bucks' => Animal::active()->where('sex', 'male')->orderBy('farm_number')->get(['id', 'farm_number', 'name']),
            'goats' => Animal::orderBy('farm_number')->get(['id', 'farm_number', 'name']),
            'pens' => Pen::with('shed')->where('kind', 'pen')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName()])->sortBy('name')->values(),
            'settings' => ['gestation' => $org->gestation_days, 'check' => $org->pregnancy_check_days, 'alert' => $org->kidding_alert_days, 'weaning' => $org->weaning_age_days],
            'options' => ['methods' => Mating::METHODS, 'checkMethods' => PregnancyCheck::METHODS, 'results' => PregnancyCheck::RESULTS, 'assisted' => Kidding::ASSISTED, 'weanMethods' => Weaning::METHODS, 'pregnancy' => Kidding::PREGNANCY] + Kidding::formOptions() + PregnancyCheck::formOptions(),
        ]);
    }

    /** Farm-born kids that reach the farm's weaning age within two weeks (or are past it) and are not weaned yet. */
    private function weaningDue(Organization $org): array
    {
        $today = now()->startOfDay();
        // Farm-born kids only, up to three months past the target age (older or bought goats were weaned elsewhere or long ago).
        $kids = Animal::with('dam')->active()->where('origin', 'born_on_farm')->where(fn ($q) => $q->whereNull('kid_stage')->orWhere('kid_stage', '!=', 'weaned'))
            ->whereNotNull('birth_date')->where('birth_date', '>=', $today->copy()->subDays($org->weaning_age_days + 90))
            ->where('birth_date', '<=', $today->copy()->subDays(max(0, $org->weaning_age_days - 14)))
            ->whereNotIn('id', Weaning::where('status', 'completed')->select('kid_id'))->orderBy('birth_date')->get();
        $postponed = Weaning::where('status', 'postponed')->whereIn('kid_id', $kids->modelKeys())->orderByDesc('id')->get()->unique('kid_id')->keyBy('kid_id');

        return $kids->map(function (Animal $k) use ($org, $postponed, $today) {
            $target = $k->birth_date->copy()->addDays($org->weaning_age_days);
            $until = $postponed[$k->id]->postponed_to ?? null;

            return [
                'id' => $k->id, 'number' => $k->farm_number, 'name' => $k->name, 'coat' => $k->coat, 'breed' => $k->breed?->name, 'stage' => $k->effectiveAge(), 'sex' => $k->sex, 'dam' => $k->dam?->farm_number,
                'age' => (int) $k->birth_date->diffInDays($today), 'target' => $target->format('d/m/Y'), 'penId' => $k->pen_id,
                'state' => $until && $until->greaterThan($today) ? 'postponed' : ($target->lessThanOrEqualTo($today) ? 'due' : 'soon'),
                'postponedTo' => $until?->format('d/m/Y'),
            ];
        })->values()->all();
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('record_mating'), 403, 'Recording a mating is not part of your role.'); // D-255
        $data = $request->validate([
            'dam_id' => ['required', 'exists:animals,id'], 'sire_id' => ['nullable', 'exists:animals,id', 'different:dam_id', \App\Support\Designations::sireRule()],
            'mated_on' => ['required', 'date', 'before_or_equal:today'], 'method' => ['required', Rule::in(array_keys(Mating::METHODS))],
            'heat_observed' => ['nullable', Rule::in(['yes', 'no', 'unknown'])], 'handler' => ['nullable', 'string', 'max:80'], 'notes' => ['nullable', 'string', 'max:500'],
            'coi_override_reason' => ['nullable', 'string', 'max:300'],
        ]);
        $dam = Animal::findOrFail($data['dam_id']);
        abort_unless($dam->sex === 'female', 422, 'The dam must be a female goat.');
        \App\Support\WelfareCare::assertRoutineAllowed($dam, 'dam_id'); // D-199
        \App\Support\ReproLoss::assertMayBreed($dam); // D-206
        if (! empty($data['sire_id'])) {
            \App\Support\WelfareCare::assertRoutineAllowed(Animal::findOrFail($data['sire_id']), 'sire_id');
        }
        if (! empty($data['sire_id']) && Animal::whereKey($data['sire_id'])->value('sex') !== 'male') {
            throw ValidationException::withMessages(['sire_id' => 'The buck must be a male goat.']);
        }
        // D-201: inbreeding of the kid this mating would produce; above the farm limit it needs the lead's reason
        \App\Support\Genetics::flush(); // fresh pedigree for this request (Octane keeps statics)
        $data['coi_pct'] = ! empty($data['sire_id']) ? \App\Support\Genetics::coi($data['sire_id'], $dam->id) : null;
        if ($data['coi_pct'] !== null && $data['coi_pct'] > \App\Support\Genetics::limit()) {
            if (empty($data['coi_override_reason'])) {
                throw ValidationException::withMessages(['sire_id' => "Inbreeding alert: the kid would be {$data['coi_pct']}% inbred (farm limit ".\App\Support\Genetics::limit().'%). Choose another buck or give the reason.']);
            }
            abort_unless($request->user()->passes('manage_breeding'), 403, 'Only the owner, manager or vet can approve a mating above the inbreeding limit.');
        } else {
            $data['coi_override_reason'] = null;
        }
        $org = $this->org();
        $mated = Carbon::parse($data['mated_on']);
        // Service cycle: this doe's services since her last kidding, including this one.
        $lastKidding = Kidding::where('dam_id', $dam->id)->max('kidded_on');
        $cycle = Mating::where('dam_id', $dam->id)->when($lastKidding, fn ($q) => $q->where('mated_on', '>', $lastKidding))->count() + 1;

        DB::transaction(function () use ($data, $dam, $mated, $org, $cycle, $request) {
            Mating::create($data + [
                'expected_kidding_on' => $mated->copy()->addDays($org->gestation_days), 'check_due_on' => $mated->copy()->addDays($org->pregnancy_check_days),
                'service_cycle' => $cycle, 'status' => 'open', 'handler' => $data['handler'] ?? $request->user()->name, 'recorded_by' => $request->user()->name,
            ]);
            if (! in_array($dam->repro_status, ['pregnant', 'expected_kidding'], true)) {
                $dam->update(['repro_status' => 'mated']);
            }
            Farm::event($dam, 'breeding', 'Mating recorded ('.Mating::METHODS[$data['method']].", service {$cycle})", $request->user()->name, $mated);
        });
        Farm::log($request->user(), "Recorded a mating for {$dam->farm_number}", "/goats/{$dam->id}");

        return back()->with('success', "Mating recorded. Pregnancy check due {$mated->copy()->addDays($org->pregnancy_check_days)->format('d/m')}, expected kidding {$mated->copy()->addDays($org->gestation_days)->format('d/m/Y')}.");
    }

    /** A pregnancy examination by a veterinarian. Several per mating; the mating's outcome and the doe's status follow the latest. */
    /**
     * D-166: a pregnancy ultrasound (or other examination) by the vet, as a KK Farms ultrasound record: status, fetal
     * count, fetal viability and the day of pregnancy, with the follow-up done for them:
     * confirmed → pregnancy record (and the expected kidding date from the vet's estimated day, when given);
     * not pregnant → open and a breeding reassessment task; inconclusive, suspected or no heartbeat → repeat ultrasound
     * task; twins or more → nutrition and monitoring task; suspected fetal loss → vet alert, the pregnancy stays open;
     * confirmed loss → recorded as a pregnancy loss (isolation, monitoring, biosecurity); hydrometra or an abnormal
     * finding → vet investigation task. The vet's findings are recorded as given; the system only schedules the work.
     */
    public function check(Request $request, Mating $mating): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'Only a veterinarian records a pregnancy check.');
        abort_unless(in_array($mating->status, ['open', 'pregnant', 'inconclusive'], true), 422, 'This mating is already closed.');
        $data = $request->validate([
            'ultrasound_status' => ['nullable', 'required_without:result', Rule::in(array_keys(PregnancyCheck::STATUSES))],
            'result' => ['nullable', Rule::in(array_keys(PregnancyCheck::RESULTS))], 'method' => ['nullable', Rule::in(array_keys(PregnancyCheck::METHODS))],
            'checked_on' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:'.$mating->mated_on->toDateString()],
            'fetuses' => ['nullable', 'integer', 'between:0,8'], 'viability' => ['nullable', Rule::in(array_keys(PregnancyCheck::VIABILITY))],
            'estimated_day' => ['nullable', 'integer', 'between:20,160'],
            'next_check_on' => ['nullable', 'required_if:result,inconclusive', 'date', 'after:today'], 'notes' => ['nullable', 'string', 'max:500'],
        ], ['next_check_on.required_if' => 'An inconclusive check needs a recheck date.'], ['next_check_on' => 'recheck date', 'ultrasound_status' => 'ultrasound result']);
        $status = $data['ultrasound_status'] ?? PregnancyCheck::STATUS_OF[$data['result']];
        $result = PregnancyCheck::RESULT_OF[$status];
        $on = Carbon::parse($data['checked_on'] ?? now());
        $day = (int) $mating->mated_on->diffInDays($on);
        $fetuses = isset($data['fetuses']) ? (int) $data['fetuses'] : null;
        $next = $data['next_check_on'] ?? (in_array($status, PregnancyCheck::REPEAT, true) ? now()->addDays(14)->toDateString() : null);
        $action = PregnancyCheck::action($status, $fetuses);
        $by = $request->user();

        DB::transaction(function () use ($mating, $data, $by, $status, $result, $on, $day, $fetuses, $next, $action) {
            PregnancyCheck::create([
                'mating_id' => $mating->id, 'dam_id' => $mating->dam_id, 'checked_on' => $on, 'method' => $data['method'] ?? 'ultrasound',
                'result' => $result, 'ultrasound_status' => $status, 'fetuses' => $fetuses, 'viability' => $data['viability'] ?? null,
                'pregnancy_day' => $day, 'action' => $action, 'next_check_on' => $next, 'examiner' => $by->name, 'notes' => $data['notes'] ?? null,
            ]);
            $dam = $mating->dam;
            Farm::event($dam, 'breeding', 'Ultrasound day '.$day.': '.PregnancyCheck::STATUSES[$status].' · '.PregnancyCheck::fetalLabel($fetuses)
                .(! empty($data['viability']) ? ' · '.PregnancyCheck::VIABILITY[$data['viability']] : '')." · next: {$action}", $by->name, $on);

            if ($status === 'CPL') {
                // A loss the vet confirmed: recorded like any pregnancy loss (isolation, monitoring, vet and biosecurity tasks).
                \App\Support\PregnancyLoss::record($mating, $on, $day < 45 ? 'EEL' : 'ABT', $by, $data['notes'] ?? null);

                return;
            }
            if ($status === 'SFL') {
                // Suspected only: the pregnancy stays as it was; the vet reassesses.
                $mating->update(['status' => $mating->status === 'open' ? 'inconclusive' : $mating->status]);
            } else {
                $mating->update(['status' => $result]);
                [$repro, $state] = match ($result) { 'pregnant' => ['pregnant', 'pregnant'], 'not_pregnant' => ['open', null], default => ['check_due', null] };
                $dam->update(['repro_status' => $repro, 'repro_state' => $state]);
            }
            // The vet's estimate of the day of pregnancy moves the expected kidding date.
            if ($result === 'pregnant' && ! empty($data['estimated_day'])) {
                $gestation = (int) (Tenant::org()?->gestation_days ?? 150);
                $mating->update(['expected_kidding_on' => $on->copy()->subDays((int) $data['estimated_day'])->addDays($gestation)->toDateString()]);
            }
            \App\Support\UltrasoundTasks::raise($mating->fresh(), $dam, $status, $fetuses, $next);
        });
        Farm::log($by, "Ultrasound for {$mating->dam->farm_number}: ".PregnancyCheck::STATUSES[$status], "/goats/{$mating->dam_id}");

        return back()->with('success', 'Ultrasound saved: '.PregnancyCheck::STATUSES[$status].'. Next: '.$action.'.');
    }

    /** D-175: a heat (oestrus) observation. Anyone working in Breeding records it; the doe shows as ready for mating. */
    public function heat(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('record_heat'), 403, 'Recording heat is not part of your role.'); // D-255
        $data = $request->validate([
            'dam_id' => ['required', 'exists:animals,id'], 'observed_on' => ['required', 'date', 'before_or_equal:today'],
            'intensity' => ['required', Rule::in(array_keys(\App\Models\HeatRecord::INTENSITY))], 'signs' => ['nullable', 'array'], 'signs.*' => ['string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['dam_id' => 'doe']);
        $doe = Animal::findOrFail($data['dam_id']);
        abort_unless($doe->sex === 'female' && $doe->status === 'active', 422, 'Heat is recorded for a doe on the farm.');
        $signs = implode(', ', $data['signs'] ?? []);
        \App\Models\HeatRecord::create([
            'dam_id' => $doe->id, 'observed_on' => $data['observed_on'], 'intensity' => $data['intensity'], 'signs' => $signs ?: null,
            'observed_by' => $request->user()->name, 'notes' => $data['notes'] ?? null,
        ]);
        Farm::event($doe, 'breeding', 'Heat observed ('.strtolower(\App\Models\HeatRecord::INTENSITY[$data['intensity']]).')'.($signs ? ": {$signs}" : ''), $request->user()->name, Carbon::parse($data['observed_on']));

        return back()->with('success', "Heat recorded for {$doe->farm_number}. She is ready for mating; next heat expected in about ".\App\Models\HeatRecord::CYCLE_DAYS.' days.');
    }

    /**
     * D-176: a care action for a newborn kid: colostrum, bottle feed, nursing check, warming or fostering to another doe.
     * A kid that is not suckling raises a critical task for the vet; fostering marks the kid as an orphan.
     */
    public function kidCare(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kid_id' => ['required', 'exists:animals,id'], 'kind' => ['required', Rule::in(array_keys(\App\Models\KidCareRecord::KINDS))],
            'given_at' => ['required', 'date', 'before_or_equal:now'], 'amount_ml' => ['nullable', 'integer', 'between:5,3000'],
            'source' => ['nullable', Rule::in(array_keys(\App\Models\KidCareRecord::SOURCES))], 'suckling' => ['nullable', 'required_if:kind,nursing_check', Rule::in(array_keys(\App\Models\KidCareRecord::SUCKLING))],
            'foster_dam_id' => ['nullable', 'required_if:kind,fostered', 'exists:animals,id'], 'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['kid_id' => 'kid', 'foster_dam_id' => 'foster doe', 'amount_ml' => 'amount']);
        $kid = Animal::findOrFail($data['kid_id']);
        $by = $request->user()->name;
        DB::transaction(function () use ($kid, $data, $by) {
            \App\Models\KidCareRecord::create($data + ['recorded_by' => $by]);
            $label = \App\Models\KidCareRecord::KINDS[$data['kind']];
            $extra = ! empty($data['amount_ml']) ? " {$data['amount_ml']} ml" : '';
            if ($data['kind'] === 'fostered') {
                $foster = Animal::findOrFail($data['foster_dam_id']);
                $kid->update(['kid_stage' => 'orphan']);
                $extra = " to {$foster->farm_number}";
                Farm::event($foster, 'breeding', "Foster mother of {$kid->farm_number}", $by);
            }
            if ($data['kind'] === 'nursing_check') {
                $extra = ': '.\App\Models\KidCareRecord::SUCKLING[$data['suckling']];
                if ($data['suckling'] === 'none') {
                    \App\Models\Task::create([
                        'title' => "Kid not suckling: {$kid->farm_number}", 'type' => 'health', 'priority' => 'critical', 'status' => 'open', 'due_on' => now()->toDateString(),
                        'animal_id' => $kid->id, 'pen_id' => $kid->pen_id, 'department_id' => \App\Models\Department::where('name', 'Veterinary')->value('id'),
                        'notes' => 'A newborn that does not suckle needs colostrum by bottle or tube and warmth now. The vet decides the treatment.',
                    ]);
                }
            }
            // D-191 (journey B): kid not viable / stable → neonatal care unit and the vet's queue; reassessed with "Viable & Stable".
            if ($data['kind'] === 'neonatal_care') {
                \App\Models\HealthRecord::create(['animal_id' => $kid->id, 'kind' => 'observation', 'occurred_on' => now()->toDateString(), 'title' => 'Newborn not stable: neonatal care',
                    'notes' => $data['notes'] ?? null, 'severity' => 'high', 'recorded_by' => $by, 'recorded_role' => 'Maternity', 'status' => 'open']);
                \App\Models\Task::create([
                    'title' => "Neonatal care: {$kid->farm_number}", 'type' => 'health', 'priority' => 'critical', 'status' => 'open', 'due_on' => now()->toDateString(),
                    'animal_id' => $kid->id, 'pen_id' => $kid->pen_id, 'department_id' => \App\Models\Department::where('name', 'Veterinary')->value('id'),
                    'notes' => 'Newborn assessed not viable / stable. Warmth, colostrum and treatment as the vet decides, then reassess.',
                ]);
                try {
                    \App\Support\Movements::transfer($kid, \App\Support\Maternity::pen('NCU-01'), new \App\Models\User(['name' => $by]), ['reason' => 'Not stable: Neonatal Care Unit', 'care' => true]);
                } catch (\Illuminate\Validation\ValidationException) {
                    // stays where it is (e.g. intake quarantine rules); the task tells the vet
                }
            }
            Farm::event($kid, 'breeding', $label.$extra, $by, \Illuminate\Support\Carbon::parse($data['given_at']));
        });

        return back()->with('success', \App\Models\KidCareRecord::KINDS[$data['kind']]." recorded for {$kid->farm_number}.");
    }

    public function close(Request $request, Mating $mating): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_breeding'), 403);
        $mating->update(['status' => 'closed']);
        Farm::event($mating->dam, 'breeding', 'Mating closed without kidding', $request->user()->name);

        return back()->with('success', 'Mating closed.');
    }

    /**
     * D-161: record a pregnancy outcome (KK Farms classification): outcome, delivery method, each live kid (sex, weak,
     * birth weight), stillborn kids by sex, the mother's status and the vet follow-up. Live kids are registered as goats
     * linked to the dam, sire and this event. An abortion or early loss closes the pregnancy as lost and raises a vet
     * review and a biosecurity task; a mother needing care raises a vet review. The vet decides the treatment.
     */
    public function kidding(Request $request, Mating $mating): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['nullable', Rule::in(array_keys(Kidding::OUTCOMES))],
            'delivery_method' => ['nullable', Rule::in(array_keys(Kidding::METHODS))],
            'kids' => ['nullable', 'array', 'max:6'], 'kids.*.sex' => ['required', Rule::in(['female', 'male'])], 'kids.*.weak' => ['nullable', 'boolean'],
            'kids.*.weight_kg' => ['nullable', 'numeric', 'between:0.3,15'],
            'alive' => ['nullable', 'integer', 'min:0', 'max:6'], 'females' => ['nullable', 'integer', 'min:0'],
            'stillborn' => ['nullable', 'integer', 'min:0', 'max:6'], 'still_male' => ['nullable', 'integer', 'min:0', 'max:6'], 'still_female' => ['nullable', 'integer', 'min:0', 'max:6'],
            'kidded_on' => ['nullable', 'date', 'before_or_equal:today', 'after:'.$mating->mated_on->toDateString()],
            'mother_status' => ['nullable', Rule::in(array_keys(Kidding::MOTHER))],
            'premature' => ['nullable', 'boolean'],
            'assisted' => ['nullable', Rule::in(array_keys(Kidding::ASSISTED))], 'attendant' => ['nullable', 'string', 'max:80'], 'notes' => ['nullable', 'string', 'max:500'],
            'complications' => ['nullable', 'string', 'max:500'], 'abortion_cause' => ['nullable', 'string', 'max:255'], 'diagnosis' => ['nullable', 'string', 'max:500'],
            'vet_officer' => ['nullable', 'string', 'max:80'], 'follow_up_on' => ['nullable', 'date'],
        ], [], ['kids.*.weight_kg' => 'birth weight', 'mother_status' => 'mother status']);
        abort_unless(in_array($mating->status, ['open', 'pregnant', 'inconclusive'], true), 422, 'This mating is already closed.');

        // Kids: one row each (new form), or the older counts (born alive, of them female).
        $kids = $data['kids'] ?? null;
        if ($kids === null) {
            $alive = (int) ($data['alive'] ?? 0);
            $females = min((int) ($data['females'] ?? 0), $alive);
            $kids = array_map(fn ($i) => ['sex' => $i < $females ? 'female' : 'male'], range(0, max(0, $alive - 1)));
            $kids = $alive ? $kids : [];
        }
        $stillMale = (int) ($data['still_male'] ?? 0);
        $stillFemale = (int) ($data['still_female'] ?? 0);
        $stillborn = isset($data['still_male']) || isset($data['still_female']) ? $stillMale + $stillFemale : (int) ($data['stillborn'] ?? 0);
        $alive = count($kids);
        $outcome = $data['outcome'] ?? ($alive && $stillborn ? 'MBO' : (! $alive && $stillborn ? 'STB' : (($data['assisted'] ?? 'no') !== 'no' ? 'AKD' : 'NKD')));
        $loss = in_array($outcome, Kidding::LOSSES, true);

        if (! $loss && $outcome !== 'UNK' && $alive + $stillborn === 0) {
            throw ValidationException::withMessages(['alive' => 'Enter the kids born alive or stillborn.']);
        }
        // The outcome must agree with the kids.
        $problem = match (true) {
            $loss && ($alive || $stillborn) => 'An abortion or early loss has no kids: set the kids to 0, or choose Stillbirth.',
            $outcome === 'STB' && ($alive || ! $stillborn) => 'Stillbirth: enter the stillborn kids and no live kids, or choose Mixed Birth Outcome.',
            $outcome === 'MBO' && (! $alive || ! $stillborn) => 'Mixed Birth Outcome needs at least one live and one stillborn kid.',
            in_array($outcome, ['NKD', 'AKD', 'CSD', 'PKD'], true) && ! $alive => 'Add the live kids, or choose Stillbirth or Abortion.',
            $outcome === 'UNK' => null,
            default => null,
        };
        if ($problem) {
            throw ValidationException::withMessages(['outcome' => $problem]);
        }
        $method = $data['delivery_method'] ?? match ($outcome) {
            'CSD' => 'CSE', 'AKD' => 'AST', 'ABT', 'EEL', 'UNK' => 'NAP', default => ($data['assisted'] ?? 'no') !== 'no' ? 'AST' : 'NRM',
        };
        $mother = $data['mother_status'] ?? ($loss ? 'PAR' : ($alive ? 'LCT' : 'HNR'));
        $on = Carbon::parse($data['kidded_on'] ?? now());
        $by = $request->user()->name;

        $user = $request->user();
        $registered = DB::transaction(function () use ($mating, $data, $kids, $alive, $stillborn, $stillMale, $stillFemale, $outcome, $loss, $method, $mother, $on, $by, $user) {
            $dam = $mating->dam;
            $sire = $mating->sire;
            // D-163: kids are born into the Maternity Unit: with their mother in post-kidding care, or neonatal care when weak.
            $breedId = $sire && $sire->breed_id === $dam->breed_id ? $dam->breed_id : Breed::where('name', 'Crossbred')->value('id') ?? $dam->breed_id;
            $weak = count(array_filter($kids, fn ($k) => ! empty($k['weak'])));

            $kidding = Kidding::create([
                'dam_id' => $dam->id, 'mating_id' => $mating->id, 'kidded_on' => $on, 'born_alive' => $alive, 'stillborn' => $stillborn,
                'outcome' => $outcome, 'delivery_method' => $method, 'premature' => $outcome === 'PKD' || ! empty($data['premature']),
                'live_male' => count(array_filter($kids, fn ($k) => $k['sex'] === 'male')), 'live_female' => count(array_filter($kids, fn ($k) => $k['sex'] === 'female')),
                'still_male' => $stillMale ?: null, 'still_female' => $stillFemale ?: null, 'weak' => $weak, 'mother_status' => $mother,
                'gestation_days' => (int) $mating->mated_on->diffInDays($on),
                'assisted' => $method === 'NRM' || $method === 'NAP' ? 'no' : ($method === 'CSE' ? 'emergency' : 'yes'),
                'attendant' => $data['attendant'] ?? $by, 'notes' => $data['notes'] ?? null, 'recorded_by' => $by,
                'complications' => $data['complications'] ?? null, 'abortion_cause' => $data['abortion_cause'] ?? null, 'diagnosis' => $data['diagnosis'] ?? null,
                'vet_officer' => $data['vet_officer'] ?? null, 'follow_up_on' => $data['follow_up_on'] ?? null,
            ]);
            $mating->update(['status' => $loss ? 'aborted' : 'kidded']);
            // D-162: she is no longer pregnant: 3 days of monitoring, then lactating (delivery) or open (loss).
            $dam->update([
                'repro_status' => $loss ? 'open' : 'lactating', 'repro_state' => $loss ? null : 'lactating',
                'monitor_until' => $on->copy()->addDays(Kidding::MONITOR_DAYS)->toDateString(), 'monitor_reason' => $outcome,
            ]);

            $label = Kidding::OUTCOMES[$outcome];
            $summary = $loss ? $label.($data['abortion_cause'] ?? null ? " · suspected cause: {$data['abortion_cause']}" : '')
                : "{$label}: ".Kidding::litterType($alive + $stillborn)." · {$alive} born alive".($stillborn ? ", {$stillborn} stillborn" : '').($weak ? " ({$weak} weak)" : '').' · '.Kidding::METHODS[$method];
            Farm::event($dam, 'breeding', $summary.' · mother: '.Kidding::MOTHER[$mother], $by, $on);

            $tags = [];
            foreach ($kids as $k) {
                $kid = Animal::create([
                    'farm_number' => ($breedId ? Breed::find($breedId)?->issueTagId($on, 'born_on_farm') : null) ?? Farm::nextFarmNumber(),
                    'sex' => $k['sex'], 'breed_id' => $breedId, 'birth_date' => $on->toDateString(), 'origin' => 'born_on_farm',
                    'sire_id' => $mating->sire_id, 'dam_id' => $dam->id, 'pen_id' => Maternity::kidPen(! empty($k['weak']))->id, 'coat' => $dam->coat, 'birth_kidding_id' => $kidding->id, 'kid_stage' => 'newborn',
                    'birth_outcome' => ! empty($k['weak']) ? 'BAW' : 'LVB',
                ]);
                Farm::event($kid, 'origin', "Born on the farm to {$dam->farm_number}".(! empty($k['weak']) ? ' · born alive, weak' : ''), $by, $on);
                if (! empty($k['weight_kg'])) {
                    WeightRecord::create(['animal_id' => $kid->id, 'measured_on' => $on->toDateString(), 'weight_kg' => $k['weight_kg'], 'context' => 'birth', 'measured_by' => $by]);
                }
                $tags[] = $kid->farm_number;
            }

            // D-163: the doe goes to the Maternity Unit: post-kidding care, or isolation after a loss.
            if ($dam->status === 'active') {
                Maternity::admit($kidding, $dam->fresh(), $user);
            }
            \App\Support\PregnancyAlerts::raise($kidding, $dam, $tags);
            \App\Support\ReproLoss::open($kidding, $dam, $mating->sire_id, $by); // D-206

            return $tags;
        });
        Farm::log($request->user(), "Recorded a pregnancy outcome for {$mating->dam->farm_number} (".Kidding::OUTCOMES[$outcome].'): '.(count($registered) ? implode(', ', $registered) : 'no live kids'), "/goats/{$mating->dam_id}");

        return back()->with('success', count($registered) ? 'Kids registered: '.implode(', ', $registered).'.' : Kidding::OUTCOMES[$outcome].' recorded.');
    }

    /** Weaning one kid: completed (weight goes to the weight log, optional move) or postponed to a date. */
    public function wean(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kid_id' => ['required', 'exists:animals,id'], 'status' => ['required', Rule::in(['completed', 'postponed'])],
            'weaned_on' => ['nullable', 'required_if:status,completed', 'date', 'before_or_equal:today'], 'weight_kg' => ['nullable', 'numeric', 'between:1,80'],
            'method' => ['nullable', Rule::in(array_keys(Weaning::METHODS))], 'pen_id' => ['nullable', 'exists:pens,id'],
            'postponed_to' => ['nullable', 'required_if:status,postponed', 'date', 'after:today'], 'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['weaned_on' => 'weaning date', 'postponed_to' => 'new date', 'weight_kg' => 'weaning weight']);
        $kid = Animal::findOrFail($data['kid_id']);
        if ($data['status'] === 'completed' && Weaning::where('kid_id', $kid->id)->where('status', 'completed')->exists()) {
            throw ValidationException::withMessages(['kid_id' => "{$kid->farm_number} is already weaned."]);
        }

        DB::transaction(function () use ($kid, $data, $request) {
            Weaning::create([
                'kid_id' => $kid->id, 'dam_id' => $kid->dam_id, 'status' => $data['status'], 'weaned_on' => $data['weaned_on'] ?? null, 'postponed_to' => $data['postponed_to'] ?? null,
                'weight_kg' => $data['weight_kg'] ?? null, 'method' => $data['method'] ?? null, 'handler' => $request->user()->name, 'notes' => $data['notes'] ?? null,
            ]);
            if ($data['status'] === 'postponed') {
                Farm::event($kid, 'breeding', 'Weaning postponed to '.Carbon::parse($data['postponed_to'])->format('d/m/Y'), $request->user()->name);

                return;
            }
            if (! empty($data['weight_kg'])) {
                WeightRecord::create(['animal_id' => $kid->id, 'measured_on' => $data['weaned_on'], 'weight_kg' => $data['weight_kg'], 'context' => 'weaning', 'measured_by' => $request->user()->name]);
            }
            $changes = ['kid_stage' => 'weaned'];
            $moved = '';
            if (! empty($data['pen_id']) && (int) $data['pen_id'] !== (int) $kid->pen_id) {
                $from = $kid->pen?->fullName() ?? 'no PEN';
                $to = Pen::with('shed')->find($data['pen_id']);
                $changes['pen_id'] = $to->id;
                Farm::event($kid, 'move', "Moved from {$from} to {$to->fullName()} at weaning", $request->user()->name);
                $moved = " · moved to {$to->fullName()}";
            }
            $kid->update($changes);
            $age = $kid->birth_date ? (int) $kid->birth_date->diffInDays(Carbon::parse($data['weaned_on'])) : null;
            Farm::event($kid, 'breeding', 'Weaned'.($age !== null ? " at {$age} days" : '').(! empty($data['weight_kg']) ? ", {$data['weight_kg']} KG" : '').$moved, $request->user()->name, Carbon::parse($data['weaned_on']));
        });
        Farm::log($request->user(), ($data['status'] === 'completed' ? 'Weaned ' : 'Postponed weaning for ').$kid->farm_number, "/goats/{$kid->id}");

        return back()->with('success', $data['status'] === 'completed' ? "{$kid->farm_number} weaned." : "Weaning for {$kid->farm_number} postponed.");
    }

    public function settings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canModule('settings'), 403);
        $data = $request->validate([
            'gestation' => ['required', 'integer', 'between:140,160'], 'check' => ['required', 'integer', 'between:20,60'],
            'alert' => ['required', 'integer', 'between:1,60'], 'weaning' => ['required', 'integer', 'between:42,180'],
        ]);
        Organization::whereKey($request->user()->org_id)->update(['gestation_days' => $data['gestation'], 'pregnancy_check_days' => $data['check'], 'kidding_alert_days' => $data['alert'], 'weaning_age_days' => $data['weaning']]);
        Farm::log($request->user(), "Breeding settings: gestation {$data['gestation']} days, check after {$data['check']}, weaning at {$data['weaning']}", '/breeding');

        return back()->with('success', 'Breeding settings saved. New matings use them; recorded dates stay as they were.');
    }
}
