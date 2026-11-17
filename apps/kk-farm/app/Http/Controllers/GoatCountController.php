<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\GoatCount;
use App\Models\Pen;
use App\Models\RfidAssignment;
use App\Models\Shed;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** D-338: count the goats by scanning their tags; the result shows total, breed and sex against what should be there. */
class GoatCountController extends Controller
{
    public function index(): Response
    {
        $tags = RfidAssignment::with('tag')->whereNull('valid_to')->get()->groupBy('animal_id')->map(fn ($g) => $g->pluck('tag.tag_code')->filter()->values());
        $goats = Animal::active()->with(['breed', 'pen.shed'])->orderBy('farm_number')->get()->map(fn (Animal $a) => [
            'id' => $a->id, 'number' => $a->farm_number, 'breed' => $a->breed?->name ?? 'Unknown breed', 'sex' => $a->sex,
            'penId' => $a->pen_id, 'shedId' => $a->pen?->shed_id, 'pen' => $a->pen?->fullName(), 'tags' => $tags[$a->id] ?? [],
        ]);

        return Inertia::render('GoatCount', [
            'goats' => $goats,
            'sheds' => Shed::orderBy('name')->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name,
                'pens' => Pen::where('shed_id', $s->id)->get()->sortBy(fn ($p) => $p->sortKey())->values()->map(fn ($p) => ['id' => $p->id, 'name' => $p->fullName()])]),
            'history' => GoatCount::latest('id')->limit(30)->get()->map(fn (GoatCount $c) => [
                'id' => $c->id, 'on' => $c->created_at->timezone(\App\Support\Feed::now()->timezone)->format('d/m/Y H:i'), 'scope' => $c->scope_label,
                'expected' => $c->expected, 'counted' => $c->counted, 'missing' => $c->missing, 'extra' => $c->extra, 'unknown' => $c->unknown,
                'byBreed' => $c->by_breed, 'by' => $c->recorded_by, 'notes' => $c->notes,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['farm', 'shed', 'pen'])],
            'shed_id' => ['nullable', 'required_if:scope,shed', 'integer', 'exists:sheds,id'],
            'pen_id' => ['nullable', 'required_if:scope,pen', 'integer', 'exists:pens,id'],
            'counted_ids' => ['present', 'array', 'max:20000'], 'counted_ids.*' => ['string', 'distinct'],
            'unknown' => ['nullable', 'array', 'max:2000'], 'unknown.*' => ['string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);
        $all = Animal::active()->with(['breed', 'pen'])->get()->keyBy('id');
        $inScope = $all->filter(fn (Animal $a) => match ($data['scope']) {
            'farm' => true,
            'shed' => $a->pen && $a->pen->shed_id === (int) $data['shed_id'],
            'pen' => $a->pen_id === (int) $data['pen_id'],
        });
        $seen = collect($data['counted_ids'])->filter(fn ($id) => $all->has($id))->unique();
        $breeds = [];
        foreach ($inScope as $a) {
            $b = $a->breed?->name ?? 'Unknown breed';
            $breeds[$b] ??= ['expected' => 0, 'counted' => 0];
            $breeds[$b]['expected']++;
        }
        foreach ($seen as $id) {
            $b = $all[$id]->breed?->name ?? 'Unknown breed';
            $breeds[$b] ??= ['expected' => 0, 'counted' => 0];
            $breeds[$b]['counted']++;
        }
        ksort($breeds);
        $label = match ($data['scope']) {
            'farm' => 'Whole farm',
            'shed' => 'Shed '.(Shed::find($data['shed_id'])?->name ?? ''),
            'pen' => 'PEN '.(Pen::find($data['pen_id'])?->fullName() ?? ''),
        };
        $count = GoatCount::create([
            'scope' => $data['scope'], 'shed_id' => $data['shed_id'] ?? null, 'pen_id' => $data['pen_id'] ?? null, 'scope_label' => $label,
            'expected' => $inScope->count(), 'counted' => $seen->count(), 'by_breed' => $breeds,
            'missing' => $inScope->keys()->diff($seen)->map(fn ($id) => $all[$id]->farm_number)->sort()->values()->all(),
            'extra' => $seen->diff($inScope->keys())->map(fn ($id) => $all[$id]->farm_number)->sort()->values()->all(),
            'unknown' => array_values(array_unique($data['unknown'] ?? [])), 'notes' => $data['notes'] ?? null, 'recorded_by' => $request->user()->name,
        ]);
        Farm::log($request->user(), "Goat count {$label}: {$count->counted} of {$count->expected}", '/goats/count');

        return back()->with('success', "Count saved: {$count->counted} of {$count->expected} goats.".(count($count->missing) ? ' '.count($count->missing).' not scanned.' : ''));
    }
}
