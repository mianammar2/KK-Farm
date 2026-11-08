<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Pen;
use App\Models\Shed;
use App\Support\Farm;
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
            'sheds' => Shed::with(['pens' => fn ($q) => $q->orderBy('name'), 'pens.animals' => fn ($q) => $q->active()->orderBy('farm_number')])->orderBy('code')->get()->map(fn (Shed $s) => [
                'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'status' => $s->status,
                'pens' => $s->pens->map(fn (Pen $p) => [
                    'id' => $p->id, 'name' => $p->name, 'purpose' => $p->purpose, 'capacity' => $p->capacity, 'count' => $p->animals->count(),
                    'goats' => $p->animals->map(fn (Animal $a) => ['id' => $a->id, 'number' => $a->farm_number, 'name' => $a->name, 'coat' => $a->coat, 'sex' => $a->sex, 'health' => $a->health_status, 'repro' => $a->repro_state]),
                ]),
            ]),
            'recent' => DB::table('animal_events')->join('animals', 'animals.id', '=', 'animal_events.animal_id')->where('type', 'move')->orderByDesc('occurred_at')->limit(8)
                ->get(['animal_events.id', 'animals.id as goat_id', 'animals.farm_number as number', 'summary', 'occurred_at', 'recorded_by']),
        ]);
    }

    /** D-051: the owner or manager sets up the farm's own sheds. The code (SHED-n) is issued automatically. */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sheds'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'unique:sheds,name'],
            'status' => ['required', Rule::in(['active', 'planned'])],
        ]);

        $n = Shed::count() + 1;
        while (Shed::where('code', "SHED-$n")->exists()) {
            $n++;
        }
        Shed::create($data + ['code' => "SHED-$n"]);
        Farm::log($request->user(), "Added shed {$data['name']}", '/sheds');

        return back()->with('success', "{$data['name']} added.");
    }

    public function update(Request $request, Shed $shed): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sheds'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('sheds', 'name')->ignore($shed->id)],
            'status' => ['required', Rule::in(['active', 'planned'])],
        ]);
        if ($data['status'] === 'planned' && Animal::active()->whereIn('pen_id', $shed->pens()->pluck('id'))->exists()) {
            throw ValidationException::withMessages(['status' => 'Move the goats out before marking this shed as planned.']);
        }

        $shed->update($data);
        Farm::log($request->user(), "Updated shed {$shed->name}", '/sheds');

        return back()->with('success', "{$shed->name} saved.");
    }

    public function storePen(Request $request, Shed $shed): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sheds'), 403);
        $data = $this->penData($request, $shed);

        $shed->pens()->create($data);
        Farm::log($request->user(), "Added pen {$data['name']} to {$shed->name}", '/sheds');

        return back()->with('success', "{$data['name']} added to {$shed->name}.");
    }

    public function updatePen(Request $request, Pen $pen): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sheds'), 403);
        $data = $this->penData($request, $pen->shed, $pen);
        if ($data['capacity'] !== null && $data['capacity'] < $pen->animals()->active()->count()) {
            throw ValidationException::withMessages(['capacity' => 'Capacity is lower than the goats already in this pen.']);
        }

        $pen->update($data);
        Farm::log($request->user(), "Updated pen {$pen->name}", '/sheds');

        return back()->with('success', "{$pen->name} saved.");
    }

    private function penData(Request $request, Shed $shed, ?Pen $pen = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('pens', 'name')->where('shed_id', $shed->id)->ignore($pen?->id)],
            'purpose' => ['required', 'string', 'max:80'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]) + ['capacity' => null];
    }

    public function transfer(Request $request): RedirectResponse
    {
        $data = $request->validate(['animal_id' => ['required', 'exists:animals,id'], 'pen_id' => ['required', 'exists:pens,id']]);

        $animal = Animal::with('pen')->findOrFail($data['animal_id']);
        abort_unless($animal->status === 'active', 422, 'This goat is no longer on the farm.');
        $pen = Pen::findOrFail($data['pen_id']);
        if ($animal->pen_id === $pen->id) {
            return back()->with('success', "{$animal->farm_number} is already in {$pen->name}.");
        }

        DB::transaction(function () use ($animal, $pen, $request): void {
            $from = $animal->pen?->name ?? 'no pen';
            $animal->update(['pen_id' => $pen->id]);
            Farm::event($animal, 'move', "Moved from $from to {$pen->name}", $request->user()->name);
        });
        Farm::log($request->user(), "Moved {$animal->farm_number} to {$pen->name}", "/goats/{$animal->id}");

        return back()->with('success', "{$animal->farm_number} moved to {$pen->name}.");
    }
}
