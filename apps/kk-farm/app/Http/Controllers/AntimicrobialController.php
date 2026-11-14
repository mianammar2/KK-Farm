<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\AnimalMovement;
use App\Models\Medicine;
use App\Models\MedicineMovement;
use App\Models\Treatment;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-209: App 05 Reporting, antimicrobial use. Every treatment with an antibiotic from the medicine store (or a product
 * named like one) over the period: courses, goats, quantity, per 100 goats, by medicine, condition and month, the use of
 * antibiotics the vet marked critically important, and doses with no traceable batch (BR-024). Records only: the vet
 * decides what to prescribe.
 */
class AntimicrobialController extends Controller
{
    public function index(Request $request): Response
    {
        $days = (int) $request->integer('days', 365);
        $days = in_array($days, [30, 90, 180, 365], true) ? $days : 365;
        $since = now()->subDays($days)->startOfDay();
        $antibiotics = Medicine::where('category', 'antibiotic')->get();
        $names = $antibiotics->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $rows = Treatment::with(['medicine', 'healthCase', 'animal.breed'])->where('given_on', '>=', $since)->get()
            ->filter(fn (Treatment $t) => $t->medicine ? $t->medicine->category === 'antibiotic' : collect($names)->contains(fn ($n) => str_starts_with(mb_strtolower($t->product), $n)))->values();
        $issued = MedicineMovement::whereIn('treatment_id', $rows->pluck('id'))->get()->groupBy('treatment_id');
        $exited = AnimalMovement::whereIn('type', array_keys(array_filter(AnimalMovement::TYPES, fn ($t) => $t[1])))->where('moved_on', '>=', $since)->distinct()->count('animal_id');
        $herd = max(1, Animal::active()->count() + $exited); // goats at risk during the period
        $cia = fn (Treatment $t) => (bool) $t->medicine?->critically_important;
        $group = fn (Collection $g) => ['courses' => $g->count(), 'goats' => $g->pluck('animal_id')->unique()->count(), 'qty' => round($g->sum('qty_used'), 1)];

        return Inertia::render('Antimicrobials', [
            'days' => $days,
            'kpi' => [
                'courses' => $rows->count(), 'goats' => $rows->pluck('animal_id')->unique()->count(), 'per100' => round($rows->count() * 100 / $herd, 1),
                'cia' => $rows->filter($cia)->count(), 'noBatch' => $rows->filter(fn ($t) => $t->medicine_id && ! ($issued[$t->id] ?? collect())->contains(fn ($m) => $m->batch))->count(),
                'noCase' => $rows->filter(fn ($t) => ! $t->health_case_id)->count(), 'herd' => $herd,
            ],
            'byMedicine' => $rows->groupBy(fn ($t) => $t->medicine?->name ?? $t->product)->map(fn ($g, $k) => ['name' => $k, 'cia' => $cia($g->first()), 'unit' => $g->first()->medicine?->unit] + $group($g))->sortByDesc('courses')->values(),
            'byCondition' => $rows->groupBy(fn ($t) => $t->healthCase?->condition ?? 'No case recorded')->map(fn ($g, $k) => ['name' => $k] + $group($g))->sortByDesc('courses')->values(),
            'byBreed' => $rows->groupBy(fn ($t) => $t->animal?->breed?->name ?? 'Unknown')->map(fn ($g, $k) => ['name' => $k] + $group($g))->sortByDesc('courses')->values(),
            'byMonth' => $rows->groupBy(fn ($t) => $t->given_on->format('Y-m'))->sortKeys()->map(fn ($g, $k) => ['month' => \Illuminate\Support\Carbon::parse($k.'-01')->format('m/Y')] + $group($g))->values(),
            'recent' => $rows->sortByDesc('given_on')->take(50)->map(fn (Treatment $t) => ['id' => $t->id, 'goatId' => $t->animal_id, 'goat' => $t->animal?->farm_number, 'on' => $t->given_on->format('d/m/Y'),
                'product' => $t->medicine?->name ?? $t->product, 'dose' => $t->dose, 'route' => $t->route, 'condition' => $t->healthCase?->condition, 'cia' => $cia($t), 'by' => $t->recorded_by,
                'batch' => ($issued[$t->id] ?? collect())->pluck('batch')->filter()->unique()->implode(', ') ?: null])->values(),
            'antibiotics' => $antibiotics->sortBy('name')->map(fn (Medicine $m) => ['id' => $m->id, 'name' => $m->name, 'cia' => $m->critically_important])->values(),
            'isVet' => in_array($request->user()->role, ['vet', 'ceo'], true),
        ]);
    }

    /** The vet marks which antibiotics are critically important (for stewardship reporting). */
    public function mark(Request $request, Medicine $medicine): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['vet', 'ceo'], true), 403, 'The vet marks critically important antibiotics.');
        abort_unless($medicine->category === 'antibiotic', 422);
        $data = $request->validate(['critically_important' => ['required', 'boolean']]);
        $medicine->update($data);
        Farm::log($request->user(), "{$medicine->name}: ".($data['critically_important'] ? 'critically important' : 'not critically important'), '/health/antimicrobials');

        return back()->with('success', "{$medicine->name} updated.");
    }
}
