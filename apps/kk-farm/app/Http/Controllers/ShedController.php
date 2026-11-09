<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\FarmLocation;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Support\Farm;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ShedController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Sheds', [
            'farms' => FarmLocation::withCount('sheds')->orderBy('id')->get()->map(fn (FarmLocation $f) => [
                'id' => $f->id, 'code' => $f->code, 'name' => $f->name, 'address' => $f->address, 'city' => $f->city, 'district' => $f->district, 'province' => $f->province,
                'country' => $f->country, 'latitude' => $f->latitude, 'longitude' => $f->longitude, 'notes' => $f->notes, 'active' => $f->active, 'place' => $f->place(), 'sheds' => $f->sheds_count,
            ]),
            'sheds' => Shed::with(['pens' => fn ($q) => $q->orderBy('name'), 'pens.animals' => fn ($q) => $q->active()->orderBy('farm_number')])->orderBy('name')->get()->map(fn (Shed $s) => [
                'id' => $s->id, 'farmId' => $s->farm_id, 'code' => $s->code, 'name' => $s->name, 'status' => $s->status, 'type' => $s->type, 'typeName' => $s->typeName(), 'purpose' => $s->purpose(),
                'pens' => $s->pens->map(fn (Pen $p) => [
                    'id' => $p->id, 'name' => "{$s->name}-{$p->name}", 'code' => $p->name, 'kind' => $p->kind, 'purpose' => $p->purpose, 'capacity' => $p->capacity, 'count' => $p->animals->count(),
                    'goats' => $p->animals->map(fn (Animal $a) => ['id' => $a->id, 'number' => $a->farm_number, 'name' => $a->name, 'coat' => $a->coat, 'sex' => $a->sex, 'health' => $a->health_status, 'repro' => $a->repro_state]),
                ]),
            ]),
            'types' => collect(Shed::TYPES)->map(fn ($t, $code) => ['code' => $code, 'name' => $t[0], 'purpose' => $t[1]])->values(),
            'prefix' => $this->prefix(),
            'board' => \App\Support\ShedBoard::build(Animal::with(['pen.shed'])->active()->orderBy('farm_number')->get()), // D-120: move goats here
        ]);
    }

    private function prefix(): string
    {
        return FarmLocation::orderBy('id')->value('code') ?: (Organization::whereKey(Tenant::current())->value('shed_prefix') ?: 'KKF');
    }

    /** D-128: the farm a shed is added to or moved to; the only farm when none is chosen. */
    private function farmFor(?int $id): FarmLocation
    {
        return $id ? FarmLocation::findOrFail($id) : FarmLocation::default();
    }

    /** D-128: add a farm (location). Its code starts the names of the sheds added to it. */
    public function storeFarm(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sheds'), 403);
        $farm = FarmLocation::create($this->farmData($request) + ['created_by' => $request->user()->name]);
        Farm::log($request->user(), "Added farm {$farm->name} ({$farm->code})", '/sheds');

        return back()->with('success', "{$farm->name} added. Add its sheds next.");
    }

    /** The code is fixed once the farm has sheds, so shed names never change behind people's backs. */
    public function updateFarm(Request $request, FarmLocation $farm): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sheds'), 403);
        $data = $this->farmData($request, $farm);
        if (strtoupper($data['code']) !== strtoupper($farm->code) && $farm->sheds()->exists()) {
            throw ValidationException::withMessages(['code' => 'The code starts the names of this farm\'s sheds, so it cannot change once sheds are added.']);
        }
        $farm->update($data);
        Farm::log($request->user(), "Updated farm {$farm->name}", '/sheds');

        return back()->with('success', "{$farm->name} saved.");
    }

    /** D-130: a farm can be deleted only when it has no sheds (move or reassign them first), and never the last farm. */
    public function destroyFarm(Request $request, FarmLocation $farm): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sheds'), 403);
        if ($farm->sheds()->exists()) {
            throw ValidationException::withMessages(['farm' => "{$farm->name} still has sheds. Move them to another farm first (edit each shed and choose the farm)."]);
        }
        if (FarmLocation::count() <= 1) {
            throw ValidationException::withMessages(['farm' => 'This is the only farm. Add another farm before deleting it.']);
        }
        $name = $farm->name;
        $farm->delete();
        Farm::log($request->user(), "Deleted farm $name ({$farm->code})", '/sheds');

        return back()->with('success', "$name deleted.");
    }

    private function farmData(Request $request, ?FarmLocation $farm = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'regex:/^[A-Z]{2,6}$/', function (string $attr, mixed $value, \Closure $fail) use ($farm) {
                if (FarmLocation::whereRaw('upper(code) = ?', [$value])->when($farm, fn ($q) => $q->whereKeyNot($farm->id))->exists()) {
                    $fail("Another farm already uses $value.");
                }
            }],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'district' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'notes' => ['nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ], ['code.regex' => 'Use 2 to 6 letters, e.g. KKF.']);

        return $data;
    }

    /** D-056: the user picks the unit type; the name and code are issued as PREFIX-TYPE-NN (e.g. KKF-BDU-01). */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sheds'), 403);
        $data = $request->validate([
            'farm_id' => ['nullable', 'integer', 'exists:farms,id'],
            'type' => ['required', Rule::in(array_keys(Shed::TYPES))],
            'status' => ['required', Rule::in(['active', 'planned'])],
        ], [], ['type' => 'unit type', 'farm_id' => 'farm']);
        $farm = $this->farmFor($data['farm_id'] ?? null);

        $shed = DB::transaction(function () use ($data, $farm) {
            DB::statement('LOCK TABLE sheds IN SHARE ROW EXCLUSIVE MODE'); // two people adding the same unit type at once get different numbers
            $name = Shed::nextName($farm->code, $data['type']);

            return Shed::create(['farm_id' => $farm->id] + $data + ['name' => $name, 'code' => $name]);
        });
        Farm::log($request->user(), "Added shed {$shed->name} ({$shed->typeName()})", '/sheds');

        return back()->with('success', "{$shed->name} · {$shed->typeName()} added.");
    }

    /** Changing the unit type gives the shed the next name of the new type; its pens and goats stay. */
    public function update(Request $request, Shed $shed): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sheds'), 403);
        $data = $request->validate([
            'farm_id' => ['nullable', 'integer', 'exists:farms,id'],
            'type' => ['required', Rule::in(array_keys(Shed::TYPES))],
            'status' => ['required', Rule::in(['active', 'planned'])],
        ], [], ['type' => 'unit type', 'farm_id' => 'farm']);
        $farm = $this->farmFor($data['farm_id'] ?? $shed->farm_id);
        $data['farm_id'] = $farm->id;
        if ($data['status'] === 'planned' && Animal::active()->whereIn('pen_id', $shed->pens()->pluck('id'))->exists()) {
            throw ValidationException::withMessages(['status' => 'Move the goats out before marking this shed as planned.']);
        }

        $old = $shed->name;
        DB::transaction(function () use ($shed, $data, $farm) {
            if ($data['type'] !== $shed->type || $farm->id !== $shed->farm_id) { // D-128: a shed moved to another farm takes that farm's code
                DB::statement('LOCK TABLE sheds IN SHARE ROW EXCLUSIVE MODE');
                $name = Shed::nextName($farm->code, $data['type'], $shed->id);
                $data += ['name' => $name, 'code' => $name, 'previous_name' => $shed->previous_name ?? "{$shed->name}|{$shed->code}"];
            }
            $shed->update($data);
        });
        Farm::log($request->user(), $old === $shed->name ? "Updated shed {$shed->name}" : "Changed shed $old to {$shed->name} ({$shed->typeName()})", '/sheds');

        return back()->with('success', $old === $shed->name ? "{$shed->name} saved." : "$old is now {$shed->name} · {$shed->typeName()}.");
    }

    public function storePen(Request $request, Shed $shed): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sheds'), 403);
        $data = $this->penData($request, $shed);

        $shed->pens()->create($data);
        Farm::log($request->user(), "Added pen {$shed->name}-{$data['name']}", '/sheds');

        return back()->with('success', "{$shed->name}-{$data['name']} added.");
    }

    public function updatePen(Request $request, Pen $pen): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sheds'), 403);
        $data = $this->penData($request, $pen->shed, $pen);
        if ($data['capacity'] !== null && $data['capacity'] < $pen->animals()->active()->count()) {
            throw ValidationException::withMessages(['capacity' => 'Capacity is lower than the goats already in this pen.']);
        }

        $pen->update($data);
        Farm::log($request->user(), "Updated pen {$pen->fullName()}", '/sheds');

        return back()->with('success', "{$pen->fullName()} saved.");
    }

    private function penData(Request $request, Shed $shed, ?Pen $pen = null): array
    {
        $area = ($pen?->kind ?? $request->input('kind')) === 'open_area';
        $request->merge(['name' => $area ? Pen::standardAreaName($request->input('name')) : Pen::standardName($request->input('name'))]); // D-057: P01; D-087: OA01

        return $request->validate([
            'name' => ['required', 'string', 'max:60', function (string $attr, mixed $value, \Closure $fail) use ($shed, $pen) {
                // One P01 per shed.
                if (Pen::where('shed_id', $shed->id)->whereRaw('lower(name) = lower(?)', [$value])->when($pen, fn ($q) => $q->whereKeyNot($pen->id))->exists()) {
                    $fail("{$shed->name}-$value already exists.");
                }
            }],
            'purpose' => ['required', 'string', 'max:80'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ], ['name.required' => $area ? 'Enter the open area number, e.g. 1 for OA01.' : 'Enter the pen number, e.g. 1 for P01.']) + ['capacity' => null] + ($pen ? [] : ['kind' => $area ? 'open_area' : 'pen']);
    }

    public function transfer(Request $request): RedirectResponse
    {
        $data = $request->validate(['animal_id' => ['required', 'exists:animals,id'], 'pen_id' => ['required', 'exists:pens,id']]);
        $animal = Animal::with('pen.shed')->findOrFail($data['animal_id']);
        $pen = Pen::with('shed')->findOrFail($data['pen_id']);
        $movement = \App\Support\Movements::transfer($animal, $pen, $request->user()); // D-076
        if (! $movement) {
            return back()->with('success', "{$animal->farm_number} is already in {$pen->fullName()}.");
        }
        Farm::log($request->user(), "Moved {$animal->farm_number} to {$pen->fullName()}", "/goats/{$animal->id}");

        return back()->with('success', "{$animal->farm_number} moved to {$pen->fullName()}.");
    }
}
