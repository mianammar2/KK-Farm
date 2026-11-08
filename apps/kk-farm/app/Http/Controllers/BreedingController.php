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
        $org = $this->org();
        $focus = $request->query('goat') ? Animal::with(['breed', 'sire.breed', 'dam.breed', 'sire.sire', 'sire.dam', 'dam.sire', 'dam.dam'])->find($request->query('goat')) : null;
        $focus ??= Animal::with(['breed', 'sire.breed', 'dam.breed', 'sire.sire', 'sire.dam', 'dam.sire', 'dam.dam'])->active()->whereNotNull('sire_id')->orderByDesc('birth_date')->first();
        $sire = $request->query('sire') ? Animal::find($request->query('sire')) : null;
        $dam = $request->query('dam') ? Animal::find($request->query('dam')) : null;
        $today = now()->startOfDay();

        $matings = Mating::with(['dam', 'sire', 'checks'])->where(fn ($q) => $q->whereIn('status', ['open', 'pregnant', 'inconclusive'])->orWhere('updated_at', '>=', now()->subDays(60)))
            ->orderByRaw("case status when 'pregnant' then 0 when 'inconclusive' then 1 when 'open' then 2 else 3 end")->orderBy('expected_kidding_on')->get();

        return Inertia::render('Breeding', [
            'matings' => $matings->map(fn (Mating $m) => [
                'id' => $m->id, 'status' => $m->status, 'outcome' => Mating::OUTCOMES[$m->status] ?? $m->status, 'method' => Mating::METHODS[$m->method] ?? $m->method,
                'heat' => $m->heat_observed, 'cycle' => $m->service_cycle, 'handler' => $m->handler, 'notes' => $m->notes, 'alert' => $m->alert($org->kidding_alert_days),
                'dam' => ['id' => $m->dam_id, 'number' => $m->dam->farm_number, 'name' => $m->dam->name, 'coat' => $m->dam->coat],
                'sire' => $m->sire ? ['id' => $m->sire_id, 'number' => $m->sire->farm_number, 'name' => $m->sire->name] : null,
                'mated' => $m->mated_on->format('j M Y'), 'expected' => $m->expected_kidding_on?->format('j M Y'), 'checkDue' => $m->check_due_on?->format('j M Y'),
                'days' => $m->expected_kidding_on ? (int) $today->diffInDays($m->expected_kidding_on, false) : null,
                'progress' => $m->expected_kidding_on ? min(100, max(0, (int) round($m->mated_on->diffInDays(now()) / max(1, $m->mated_on->diffInDays($m->expected_kidding_on)) * 100))) : 0,
                'active' => in_array($m->status, ['open', 'pregnant', 'inconclusive'], true),
                'checks' => $m->checks->map(fn (PregnancyCheck $c) => [
                    'id' => $c->id, 'on' => $c->checked_on->format('j M Y'), 'method' => PregnancyCheck::METHODS[$c->method] ?? $c->method, 'result' => PregnancyCheck::RESULTS[$c->result] ?? $c->result,
                    'fetuses' => $c->fetuses, 'next' => $c->next_check_on?->format('j M Y'), 'by' => $c->examiner, 'notes' => $c->notes,
                ])->values(),
            ]),
            'kiddings' => Kidding::with(['dam', 'kids'])->orderByDesc('kidded_on')->orderByDesc('id')->limit(30)->get()->map(fn (Kidding $k) => [
                'id' => $k->id, 'goatId' => $k->dam_id, 'dam' => $k->dam->farm_number, 'on' => $k->kidded_on->format('j M Y'), 'alive' => $k->born_alive, 'stillborn' => $k->stillborn,
                'total' => $k->born_alive + $k->stillborn, 'litter' => Kidding::litterType($k->born_alive + $k->stillborn), 'assisted' => Kidding::ASSISTED[$k->assisted] ?? $k->assisted,
                'attendant' => $k->attendant ?? $k->recorded_by, 'notes' => $k->notes, 'kids' => $k->kids->map(fn ($a) => ['id' => $a->id, 'number' => $a->farm_number])->values(),
            ]),
            'weaning' => $this->weaningDue($org),
            'weaned' => Weaning::with('kid')->where('status', 'completed')->orderByDesc('weaned_on')->limit(20)->get()->map(fn (Weaning $w) => [
                'id' => $w->id, 'kidId' => $w->kid_id, 'kid' => $w->kid->farm_number, 'on' => $w->weaned_on->format('j M Y'),
                'age' => $w->kid->birth_date ? (int) $w->kid->birth_date->diffInDays($w->weaned_on) : null, 'weight' => $w->weight_kg, 'method' => Weaning::METHODS[$w->method] ?? $w->method, 'by' => $w->handler,
            ]),
            'performance' => DB::table('kiddings')->join('animals', 'animals.id', '=', 'kiddings.dam_id')->groupBy('animals.id', 'animals.farm_number', 'animals.name')
                ->selectRaw('animals.id, animals.farm_number as number, animals.name, count(*) as kiddings, sum(born_alive) as alive, sum(stillborn) as stillborn, round(avg(born_alive + stillborn)::numeric, 1)::float as litter')
                ->orderByDesc('alive')->limit(20)->get(),
            'focus' => $focus ? ['id' => $focus->id, 'number' => $focus->farm_number, 'tree' => Farm::pedigree($focus)] : null,
            'pairCheck' => $sire && $dam ? ['shared' => count(Farm::sharedAncestors($sire, $dam)), 'sire' => $sire->farm_number, 'dam' => $dam->farm_number] : null,
            'does' => Animal::active()->where('sex', 'female')->orderBy('farm_number')->get(['id', 'farm_number', 'name']),
            'bucks' => Animal::active()->where('sex', 'male')->orderBy('farm_number')->get(['id', 'farm_number', 'name']),
            'goats' => Animal::orderBy('farm_number')->get(['id', 'farm_number', 'name']),
            'pens' => Pen::with('shed')->where('kind', 'pen')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName()])->sortBy('name')->values(),
            'settings' => ['gestation' => $org->gestation_days, 'check' => $org->pregnancy_check_days, 'alert' => $org->kidding_alert_days, 'weaning' => $org->weaning_age_days],
            'options' => ['methods' => Mating::METHODS, 'checkMethods' => PregnancyCheck::METHODS, 'results' => PregnancyCheck::RESULTS, 'assisted' => Kidding::ASSISTED, 'weanMethods' => Weaning::METHODS],
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
                'id' => $k->id, 'number' => $k->farm_number, 'name' => $k->name, 'coat' => $k->coat, 'sex' => $k->sex, 'dam' => $k->dam?->farm_number,
                'age' => (int) $k->birth_date->diffInDays($today), 'target' => $target->format('j M Y'), 'penId' => $k->pen_id,
                'state' => $until && $until->greaterThan($today) ? 'postponed' : ($target->lessThanOrEqualTo($today) ? 'due' : 'soon'),
                'postponedTo' => $until?->format('j M Y'),
            ];
        })->values()->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'dam_id' => ['required', 'exists:animals,id'], 'sire_id' => ['nullable', 'exists:animals,id', 'different:dam_id'],
            'mated_on' => ['required', 'date', 'before_or_equal:today'], 'method' => ['required', Rule::in(array_keys(Mating::METHODS))],
            'heat_observed' => ['nullable', Rule::in(['yes', 'no', 'unknown'])], 'handler' => ['nullable', 'string', 'max:80'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $dam = Animal::findOrFail($data['dam_id']);
        abort_unless($dam->sex === 'female', 422, 'The dam must be a female goat.');
        if (! empty($data['sire_id']) && Animal::whereKey($data['sire_id'])->value('sex') !== 'male') {
            throw ValidationException::withMessages(['sire_id' => 'The buck must be a male goat.']);
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

        return back()->with('success', "Mating recorded. Pregnancy check due {$mated->copy()->addDays($org->pregnancy_check_days)->format('j M')}, expected kidding {$mated->copy()->addDays($org->gestation_days)->format('j M Y')}.");
    }

    /** A pregnancy examination by a veterinarian. Several per mating; the mating's outcome and the doe's status follow the latest. */
    public function check(Request $request, Mating $mating): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'Only a veterinarian records a pregnancy check.');
        abort_unless(in_array($mating->status, ['open', 'pregnant', 'inconclusive'], true), 422, 'This mating is already closed.');
        $data = $request->validate([
            'result' => ['required', Rule::in(array_keys(PregnancyCheck::RESULTS))], 'method' => ['nullable', Rule::in(array_keys(PregnancyCheck::METHODS))],
            'checked_on' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:'.$mating->mated_on->toDateString()],
            'fetuses' => ['nullable', 'integer', 'between:1,6'], 'next_check_on' => ['nullable', 'required_if:result,inconclusive', 'date', 'after:today'], 'notes' => ['nullable', 'string', 'max:500'],
        ], ['next_check_on.required_if' => 'An inconclusive check needs a recheck date.'], ['next_check_on' => 'recheck date']);

        DB::transaction(function () use ($mating, $data, $request) {
            PregnancyCheck::create([
                'mating_id' => $mating->id, 'dam_id' => $mating->dam_id, 'checked_on' => $data['checked_on'] ?? now(), 'method' => $data['method'] ?? 'vet_exam',
                'result' => $data['result'], 'fetuses' => $data['fetuses'] ?? null, 'next_check_on' => $data['next_check_on'] ?? null,
                'examiner' => $request->user()->name, 'notes' => $data['notes'] ?? null,
            ]);
            [$status, $repro] = match ($data['result']) {
                'pregnant' => ['pregnant', 'pregnant'],
                'not_pregnant' => ['not_pregnant', 'open'],
                'inconclusive' => ['inconclusive', 'check_due'],
                'pregnancy_loss' => ['aborted', 'open'],
            };
            $mating->update(['status' => $status]);
            $mating->dam->update(['repro_status' => $repro, 'repro_state' => $repro === 'pregnant' ? 'pregnant' : null]);
            Farm::event($mating->dam, 'breeding', 'Pregnancy check: '.strtolower(PregnancyCheck::RESULTS[$data['result']]).(! empty($data['fetuses']) ? " ({$data['fetuses']} fetuses)" : ''), $request->user()->name);
        });
        Farm::log($request->user(), "Pregnancy check for {$mating->dam->farm_number}", "/goats/{$mating->dam_id}");

        return back()->with('success', 'Pregnancy check saved.');
    }

    public function close(Request $request, Mating $mating): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['ceo', 'manager', 'vet'], true), 403);
        $mating->update(['status' => 'closed']);
        Farm::event($mating->dam, 'breeding', 'Mating closed without kidding', $request->user()->name);

        return back()->with('success', 'Mating closed.');
    }

    public function kidding(Request $request, Mating $mating): RedirectResponse
    {
        $data = $request->validate([
            'alive' => ['required', 'integer', 'min:0', 'max:6'], 'stillborn' => ['required', 'integer', 'min:0', 'max:6'], 'females' => ['required', 'integer', 'min:0', 'lte:alive'],
            'kidded_on' => ['nullable', 'date', 'before_or_equal:today', 'after:'.$mating->mated_on->toDateString()],
            'assisted' => ['nullable', Rule::in(array_keys(Kidding::ASSISTED))], 'attendant' => ['nullable', 'string', 'max:80'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        abort_unless(in_array($mating->status, ['open', 'pregnant', 'inconclusive'], true), 422, 'This mating is already closed.');
        if ((int) $data['alive'] + (int) $data['stillborn'] === 0) {
            throw ValidationException::withMessages(['alive' => 'Enter the kids born alive or stillborn.']);
        }
        $on = Carbon::parse($data['kidded_on'] ?? now());

        $kids = DB::transaction(function () use ($mating, $data, $request, $on) {
            $dam = $mating->dam;
            $sire = $mating->sire;
            // D-071: kids stay with their mother; a farm "Kids" pen only when the doe has no pen.
            $kidsPen = $dam->pen_id ?? Pen::where('purpose', 'Kids')->value('id');
            $breedId = $sire && $sire->breed_id === $dam->breed_id ? $dam->breed_id : Breed::where('name', 'Crossbred')->value('id') ?? $dam->breed_id;

            $kidding = Kidding::create([
                'dam_id' => $dam->id, 'mating_id' => $mating->id, 'kidded_on' => $on, 'born_alive' => $data['alive'], 'stillborn' => $data['stillborn'],
                'assisted' => $data['assisted'] ?? 'no', 'attendant' => $data['attendant'] ?? $request->user()->name, 'notes' => $data['notes'] ?? null, 'recorded_by' => $request->user()->name,
            ]);
            $mating->update(['status' => 'kidded']);
            $dam->update(['repro_status' => (int) $data['alive'] > 0 ? 'lactating' : 'kidded', 'repro_state' => (int) $data['alive'] > 0 ? 'lactating' : null]);
            $total = (int) $data['alive'] + (int) $data['stillborn'];
            Farm::event($dam, 'breeding', 'Kidded: '.Kidding::litterType($total)." · {$data['alive']} born alive".($data['stillborn'] ? ", {$data['stillborn']} stillborn" : '').(($data['assisted'] ?? 'no') !== 'no' ? ' · assisted' : ''), $request->user()->name, $on);

            $kids = [];
            for ($i = 1; $i <= (int) $data['alive']; $i++) {
                $kid = Animal::create([
                    'farm_number' => ($breedId ? Breed::find($breedId)?->issueTagId($on, 'born_on_farm') : null) ?? Farm::nextFarmNumber(),
                    'sex' => $i <= $data['females'] ? 'female' : 'male', 'breed_id' => $breedId, 'birth_date' => $on->toDateString(), 'origin' => 'born_on_farm',
                    'sire_id' => $mating->sire_id, 'dam_id' => $dam->id, 'pen_id' => $kidsPen, 'coat' => $dam->coat, 'birth_kidding_id' => $kidding->id, 'kid_stage' => 'newborn',
                ]);
                Farm::event($kid, 'origin', "Born on the farm to {$dam->farm_number}", $request->user()->name, $on);
                $kids[] = $kid->farm_number;
            }

            return $kids;
        });
        Farm::log($request->user(), "Recorded a kidding for {$mating->dam->farm_number}: ".(count($kids) ? implode(', ', $kids) : 'no live kids'), "/goats/{$mating->dam_id}");

        return back()->with('success', count($kids) ? 'Kids registered: '.implode(', ', $kids).'. Add each kid\'s birth weight on its record.' : 'Kidding recorded.');
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
                Farm::event($kid, 'breeding', 'Weaning postponed to '.Carbon::parse($data['postponed_to'])->format('j M Y'), $request->user()->name);

                return;
            }
            if (! empty($data['weight_kg'])) {
                WeightRecord::create(['animal_id' => $kid->id, 'measured_on' => $data['weaned_on'], 'weight_kg' => $data['weight_kg'], 'context' => 'weaning', 'measured_by' => $request->user()->name]);
            }
            $changes = ['kid_stage' => 'weaned'];
            $moved = '';
            if (! empty($data['pen_id']) && (int) $data['pen_id'] !== (int) $kid->pen_id) {
                $from = $kid->pen?->fullName() ?? 'no pen';
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
