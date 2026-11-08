<?php

namespace App\Http\Controllers;

use App\Models\Animal;
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
            'sheds' => Shed::with(['pens' => fn ($q) => $q->orderBy('name'), 'pens.animals' => fn ($q) => $q->active()->orderBy('farm_number')])->orderBy('name')->get()->map(fn (Shed $s) => [
                'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'status' => $s->status, 'type' => $s->type, 'typeName' => $s->typeName(), 'purpose' => $s->purpose(),
                'pens' => $s->pens->map(fn (Pen $p) => [
                    'id' => $p->id, 'name' => "{$s->name}-{$p->name}", 'code' => $p->name, 'purpose' => $p->purpose, 'capacity' => $p->capacity, 'count' => $p->animals->count(),
                    'goats' => $p->animals->map(fn (Animal $a) => ['id' => $a->id, 'number' => $a->farm_number, 'name' => $a->name, 'coat' => $a->coat, 'sex' => $a->sex, 'health' => $a->health_status, 'repro' => $a->repro_state]),
                ]),
            ]),
            'types' => collect(Shed::TYPES)->map(fn ($t, $code) => ['code' => $code, 'name' => $t[0], 'purpose' => $t[1]])->values(),
            'prefix' => $this->prefix(),
            'recent' => DB::table('animal_events')->join('animals', 'animals.id', '=', 'animal_events.animal_id')->where('type', 'move')->orderByDesc('occurred_at')->limit(8)
                ->get(['animal_events.id', 'animals.id as goat_id', 'animals.farm_number as number', 'summary', 'occurred_at', 'recorded_by']),
        ]);
    }

    private function prefix(): string
    {
        return Organization::whereKey(Tenant::current())->value('shed_prefix') ?: 'KKF';
    }

    /** D-056: the user picks the unit type; the name and code are issued as PREFIX-TYPE-NN (e.g. KKF-BDU-01). */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sheds'), 403);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Shed::TYPES))],
            'status' => ['required', Rule::in(['active', 'planned'])],
        ], [], ['type' => 'unit type']);

        $shed = DB::transaction(function () use ($data) {
            DB::statement('LOCK TABLE sheds IN SHARE ROW EXCLUSIVE MODE'); // two people adding the same unit type at once get different numbers
            $name = Shed::nextName($this->prefix(), $data['type']);

            return Shed::create($data + ['name' => $name, 'code' => $name]);
        });
        Farm::log($request->user(), "Added shed {$shed->name} ({$shed->typeName()})", '/sheds');

        return back()->with('success', "{$shed->name} · {$shed->typeName()} added.");
    }

    /** Changing the unit type gives the shed the next name of the new type; its pens and goats stay. */
    public function update(Request $request, Shed $shed): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sheds'), 403);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Shed::TYPES))],
            'status' => ['required', Rule::in(['active', 'planned'])],
        ], [], ['type' => 'unit type']);
        if ($data['status'] === 'planned' && Animal::active()->whereIn('pen_id', $shed->pens()->pluck('id'))->exists()) {
            throw ValidationException::withMessages(['status' => 'Move the goats out before marking this shed as planned.']);
        }

        $old = $shed->name;
        DB::transaction(function () use ($shed, $data) {
            if ($data['type'] !== $shed->type) {
                DB::statement('LOCK TABLE sheds IN SHARE ROW EXCLUSIVE MODE');
                $name = Shed::nextName($this->prefix(), $data['type'], $shed->id);
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
        $request->merge(['name' => Pen::standardName($request->input('name'))]); // D-057: stored as P01, shown as SHED-P01

        return $request->validate([
            'name' => ['required', 'string', 'max:60', function (string $attr, mixed $value, \Closure $fail) use ($shed, $pen) {
                // One P01 per shed.
                if (Pen::where('shed_id', $shed->id)->whereRaw('lower(name) = lower(?)', [$value])->when($pen, fn ($q) => $q->whereKeyNot($pen->id))->exists()) {
                    $fail("{$shed->name}-$value already exists.");
                }
            }],
            'purpose' => ['required', 'string', 'max:80'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ], ['name.required' => 'Enter the pen number, e.g. 1 for P01.']) + ['capacity' => null];
    }

    public function transfer(Request $request): RedirectResponse
    {
        $data = $request->validate(['animal_id' => ['required', 'exists:animals,id'], 'pen_id' => ['required', 'exists:pens,id']]);

        $animal = Animal::with('pen')->findOrFail($data['animal_id']);
        abort_unless($animal->status === 'active', 422, 'This goat is no longer on the farm.');
        $pen = Pen::findOrFail($data['pen_id']);
        if ($animal->pen_id === $pen->id) {
            return back()->with('success', "{$animal->farm_number} is already in {$pen->fullName()}.");
        }

        DB::transaction(function () use ($animal, $pen, $request): void {
            $from = $animal->pen?->fullName() ?? 'no pen';
            $animal->update(['pen_id' => $pen->id]);
            Farm::event($animal, 'move', "Moved from $from to {$pen->fullName()}", $request->user()->name);
        });
        Farm::log($request->user(), "Moved {$animal->farm_number} to {$pen->fullName()}", "/goats/{$animal->id}");

        return back()->with('success', "{$animal->farm_number} moved to {$pen->fullName()}.");
    }
}
