<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Kidding;
use App\Models\Mating;
use App\Models\Pen;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BreedingController extends Controller
{
    public function index(Request $request): Response
    {
        $focus = $request->query('goat') ? Animal::with(['breed', 'sire.breed', 'dam.breed', 'sire.sire', 'sire.dam', 'dam.sire', 'dam.dam'])->find($request->query('goat')) : null;
        $focus ??= Animal::with(['breed', 'sire.breed', 'dam.breed', 'sire.sire', 'sire.dam', 'dam.sire', 'dam.dam'])->active()->whereNotNull('sire_id')->orderByDesc('birth_date')->first();

        $sire = $request->query('sire') ? Animal::find($request->query('sire')) : null;
        $dam = $request->query('dam') ? Animal::find($request->query('dam')) : null;

        $map = fn (Mating $m) => [
            'id' => $m->id, 'status' => $m->status, 'method' => $m->method,
            'dam' => ['id' => $m->dam_id, 'number' => $m->dam->farm_number, 'name' => $m->dam->name, 'coat' => $m->dam->coat, 'sex' => 'female'],
            'sire' => $m->sire ? ['id' => $m->sire_id, 'number' => $m->sire->farm_number, 'name' => $m->sire->name] : null,
            'mated' => $m->mated_on->format('j M Y'), 'expected' => $m->expected_kidding_on?->format('j M Y'),
            'days' => $m->expected_kidding_on ? (int) now()->startOfDay()->diffInDays($m->expected_kidding_on, false) : null,
            'progress' => $m->expected_kidding_on ? min(100, max(0, (int) round($m->mated_on->diffInDays(now()) / max(1, $m->mated_on->diffInDays($m->expected_kidding_on)) * 100))) : 0,
        ];

        return Inertia::render('Breeding', [
            'pregnant' => Mating::with(['dam', 'sire'])->where('status', 'pregnant')->orderBy('expected_kidding_on')->get()->map($map),
            'open' => Mating::with(['dam', 'sire'])->where('status', 'open')->orderByDesc('mated_on')->get()->map($map),
            'recent' => Kidding::with('dam')->orderByDesc('kidded_on')->limit(8)->get()->map(fn (Kidding $k) => ['id' => $k->id, 'goatId' => $k->dam_id, 'dam' => $k->dam->farm_number, 'on' => $k->kidded_on->format('j M Y'), 'alive' => $k->born_alive, 'stillborn' => $k->stillborn]),
            'performance' => DB::table('kiddings')->join('animals', 'animals.id', '=', 'kiddings.dam_id')->groupBy('animals.id', 'animals.farm_number', 'animals.name')
                ->selectRaw('animals.id, animals.farm_number as number, animals.name, count(*) as kiddings, sum(born_alive) as alive, sum(stillborn) as stillborn')->orderByDesc('alive')->limit(8)->get(),
            'focus' => $focus ? ['id' => $focus->id, 'number' => $focus->farm_number, 'tree' => Farm::pedigree($focus)] : null,
            'pairCheck' => $sire && $dam ? ['shared' => count(Farm::sharedAncestors($sire, $dam)), 'sire' => $sire->farm_number, 'dam' => $dam->farm_number] : null,
            'does' => Animal::active()->where('sex', 'female')->orderBy('farm_number')->get(['id', 'farm_number', 'name']),
            'bucks' => Animal::active()->where('sex', 'male')->orderBy('farm_number')->get(['id', 'farm_number', 'name']),
            'goats' => Animal::orderBy('farm_number')->get(['id', 'farm_number', 'name']),
            'gestationDays' => config('kkfarm.demo_gestation_days'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['dam_id' => ['required', 'exists:animals,id'], 'sire_id' => ['nullable', 'exists:animals,id', 'different:dam_id'], 'mated_on' => ['required', 'date', 'before_or_equal:today'], 'method' => ['required', Rule::in(['natural', 'ai'])]]);

        $dam = Animal::findOrFail($data['dam_id']);
        abort_unless($dam->sex === 'female', 422, 'The dam must be a female goat.');
        $mated = now()->parse($data['mated_on']);
        Mating::create($data + ['expected_kidding_on' => $mated->copy()->addDays(config('kkfarm.demo_gestation_days')), 'status' => 'open', 'recorded_by' => $request->user()->name]);
        Farm::event($dam, 'breeding', 'Mating recorded', $request->user()->name, $mated);
        Farm::log($request->user(), "Recorded a mating for {$dam->farm_number}", "/goats/{$dam->id}");

        return back()->with('success', 'Mating recorded.');
    }

    public function check(Request $request, Mating $mating): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'Only a veterinarian records a pregnancy check.');
        $data = $request->validate(['result' => ['required', Rule::in(['pregnant', 'not_pregnant'])]]);

        DB::transaction(function () use ($mating, $data, $request): void {
            $mating->update(['status' => $data['result']]);
            $mating->dam->update(['repro_state' => $data['result'] === 'pregnant' ? 'pregnant' : null]);
            Farm::event($mating->dam, 'breeding', 'Pregnancy check: '.str_replace('_', ' ', $data['result']), $request->user()->name);
        });
        Farm::log($request->user(), "Pregnancy check for {$mating->dam->farm_number}", "/goats/{$mating->dam_id}");

        return back()->with('success', 'Pregnancy check saved.');
    }

    public function kidding(Request $request, Mating $mating): RedirectResponse
    {
        $data = $request->validate(['alive' => ['required', 'integer', 'min:0', 'max:6'], 'stillborn' => ['required', 'integer', 'min:0', 'max:6'], 'females' => ['required', 'integer', 'min:0', 'lte:alive']]);
        abort_unless($mating->status === 'pregnant', 422, 'This mating is not an open pregnancy.');

        $kids = DB::transaction(function () use ($mating, $data, $request) {
            $dam = $mating->dam;
            $sire = $mating->sire;
            // D-071: kids stay with their mother; a farm "Kids" pen only when the doe has no pen.
            $kidsPen = $dam->pen_id ?? Pen::where('purpose', 'Kids')->value('id');
            $breedId = $sire && $sire->breed_id === $dam->breed_id ? $dam->breed_id : Breed::where('name', 'Crossbred')->value('id') ?? $dam->breed_id;

            Kidding::create(['dam_id' => $dam->id, 'mating_id' => $mating->id, 'kidded_on' => now(), 'born_alive' => $data['alive'], 'stillborn' => $data['stillborn'], 'recorded_by' => $request->user()->name]);
            $mating->update(['status' => 'kidded']);
            $dam->update(['repro_state' => 'lactating']);
            Farm::event($dam, 'breeding', "Kidded: {$data['alive']} born alive", $request->user()->name);

            $kids = [];
            for ($i = 1; $i <= (int) $data['alive']; $i++) {
                $kid = Animal::create([
                    'farm_number' => ($breedId ? \App\Models\Breed::find($breedId)?->issueTagId(null, 'born_on_farm') : null) /* D-059 */ ?? Farm::nextFarmNumber(), 'sex' => $i <= $data['females'] ? 'female' : 'male', 'breed_id' => $breedId,
                    'birth_date' => now()->toDateString(), 'origin' => 'born_on_farm', 'sire_id' => $mating->sire_id, 'dam_id' => $dam->id, 'pen_id' => $kidsPen, 'coat' => $dam->coat,
                ]);
                Farm::event($kid, 'origin', "Born on the farm to {$dam->farm_number}", $request->user()->name);
                $kids[] = $kid->farm_number;
            }

            return $kids;
        });
        Farm::log($request->user(), "Recorded a kidding for {$mating->dam->farm_number}: ".(count($kids) ? implode(', ', $kids) : 'no live kids'), "/goats/{$mating->dam_id}");

        return back()->with('success', count($kids) ? 'Kids registered: '.implode(', ', $kids).'.' : 'Kidding recorded.');
    }
}
