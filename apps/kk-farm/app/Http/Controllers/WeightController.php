<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\WeightRecord;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WeightController extends Controller
{
    public function index(): Response
    {
        $goats = Animal::active()->with(['breed', 'pen', 'latestWeight', 'photos', 'weights'])->orderBy('farm_number')->get()->map(function (Animal $a): array {
            $weights = $a->weights;

            return $a->card() + [
                'adg' => Farm::adg($weights),
                'previous' => $weights->count() > 1 ? $weights[$weights->count() - 2]->weight_kg : null,
                'weighedOn' => $weights->last()?->measured_on->format('j M'),
                'daysAgo' => $weights->last() ? (int) $weights->last()->measured_on->diffInDays(now()) : null,
                'bodyScore' => $weights->last()?->body_score,
            ];
        });
        // D-078: the workbook's weight log: each weighing with the previous one, days between, gain and ADG worked out.
        $recent = WeightRecord::with('animal')->orderByDesc('measured_on')->orderByDesc('id')->limit(100)->get();
        // Each goat's weighings in order, loaded once, so the previous weighing is a lookup rather than a query per row.
        $history = WeightRecord::whereIn('animal_id', $recent->pluck('animal_id')->unique())->orderBy('measured_on')->orderBy('id')->get()->groupBy('animal_id')->map->values();
        $log = $recent->map(function (WeightRecord $w) use ($history) {
            $list = $history[$w->animal_id];
            $index = $list->search(fn ($x) => $x->id === $w->id);
            $prev = $index > 0 ? $list[$index - 1] : null;
            $days = $prev ? max(1, (int) $prev->measured_on->diffInDays($w->measured_on)) : null;

            return [
                'id' => $w->id, 'goatId' => $w->animal_id, 'goat' => $w->animal->farm_number, 'on' => $w->measured_on->format('j M Y'), 'kg' => (float) $w->weight_kg,
                'previous' => $prev ? (float) $prev->weight_kg : null, 'days' => $days, 'gain' => $prev ? round($w->weight_kg - $prev->weight_kg, 2) : null,
                'adg' => $prev ? (int) round(($w->weight_kg - $prev->weight_kg) * 1000 / $days) : null, 'score' => $w->body_score,
                'heightIn' => $w->height_cm !== null ? round($w->height_cm / 2.54, 1) : null, 'context' => $w->context, 'by' => $w->measured_by,
            ];
        });

        return Inertia::render('Weights', [
            'goats' => $goats,
            'log' => $log,
            'byBreed' => DB::table('weight_records')->join('animals', 'animals.id', '=', 'weight_records.animal_id')->join('breeds', 'breeds.id', '=', 'animals.breed_id')
                ->where('measured_on', '>=', now()->subMonths(6)->startOfMonth())->groupByRaw("breeds.name, to_char(measured_on, 'YYYY-MM')")
                ->selectRaw("breeds.name as breed, to_char(measured_on, 'YYYY-MM') as month, round(avg(weight_kg)::numeric, 1)::float as kg")->orderBy('month')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'animal_id' => ['required', 'exists:animals,id'], 'weight_kg' => ['required', 'numeric', 'min:0.5', 'max:200'], 'measured_on' => ['nullable', 'date', 'before_or_equal:today'],
            'body_score' => ['nullable', 'integer', 'between:1,5'], 'height_in' => ['nullable', 'numeric', 'between:4,80'], // D-078
        ]);

        $animal = Animal::findOrFail($data['animal_id']);
        abort_unless($animal->status === 'active', 422, 'This goat is no longer on the farm.');
        $heightCm = isset($data['height_in']) ? round($data['height_in'] * 2.54, 1) : null;
        WeightRecord::create(['animal_id' => $animal->id, 'weight_kg' => $data['weight_kg'], 'measured_on' => $data['measured_on'] ?? now()->toDateString(), 'measured_by' => $request->user()->name, 'body_score' => $data['body_score'] ?? null, 'height_cm' => $heightCm]);
        if ($heightCm) {
            $animal->update(['height_cm' => $heightCm]);
        }
        Farm::event($animal, 'weight', "Weighed {$data['weight_kg']} kg".(! empty($data['body_score']) ? ", body score {$data['body_score']}" : '').(! empty($data['height_in']) ? ", {$data['height_in']} in" : ''), $request->user()->name);
        Farm::log($request->user(), "Weighed {$animal->farm_number} at {$data['weight_kg']} kg", "/goats/{$animal->id}");

        return back()->with('success', "Weight saved for {$animal->farm_number}.");
    }
}
