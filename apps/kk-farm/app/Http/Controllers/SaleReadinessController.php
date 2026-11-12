<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\SaleClearance;
use App\Support\Designations;
use App\Support\Farm;
use App\Support\Lifecycle;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-232: Sales → Sale Readiness. Goats at market weight wait for a sale clearance; a cleared goat is Sale Ready until
 * a blocker appears (sick, withdrawal, hold), which blocks it again by itself. Breeding stock and cull goats need a reason.
 */
class SaleReadinessController extends Controller
{
    public function index(Request $request): Response
    {
        $rows = Animal::active()->with('breed')->orderBy('farm_number')->get()->map(function (Animal $a) {
            $s = Lifecycle::statuses($a);

            return ['id' => $a->id, 'number' => $a->farm_number, 'name' => $a->name, 'sex' => $a->sex, 'breed' => $a->breed?->name, 'code' => $a->classCode(), 'roles' => Designations::codes($a),
                'sale' => $s['sale']['code'], 'saleLabel' => $s['sale']['label'], 'blockers' => $s['sale']['blockers'], 'fattening' => $s['fattening']['note'] ?? null, 'health' => $s['health']['label']];
        })->filter(fn ($r) => $r['sale'] !== 'not_for_sale')->values();

        return Inertia::render('Sales/Readiness', [
            'rows' => $rows,
            'weights' => Lifecycle::marketWeights(),
            'history' => SaleClearance::latest('decided_on')->latest('id')->limit(30)->get()->map(fn ($c) => ['id' => $c->id, 'goatId' => $c->animal_id, 'number' => Animal::find($c->animal_id)?->farm_number,
                'decision' => $c->decision, 'on' => $c->decided_on->format('j M Y'), 'by' => $c->decided_by, 'reason' => $c->reason]),
            'canClear' => $request->user()->passes('manage_sales'),
        ]);
    }

    public function decide(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sales'), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['cleared', 'withdrawn'])], 'reason' => ['nullable', 'string', 'max:500']]);
        $s = Lifecycle::statuses($animal);
        if ($data['decision'] === 'cleared') {
            if ($s['sale']['blockers']) {
                throw ValidationException::withMessages(['decision' => 'Cannot clear for sale: '.implode('; ', $s['sale']['blockers']).'.']);
            }
            $stock = array_intersect(Designations::codes($animal), ['BB', 'BD', 'RB', 'RD', 'PS', 'PD', 'DD', 'CM', 'CF']);
            $early = $s['sale']['code'] !== 'market_weight';
            if (($stock || $early) && mb_strlen(trim((string) ($data['reason'] ?? ''))) < 10) {
                throw ValidationException::withMessages(['reason' => $stock ? 'Breeding stock and cull goats need the management reason for this sale (at least 10 characters).' : 'Not at market weight: give the reason for clearing it now (at least 10 characters).']);
            }
        }
        $by = $request->user()->name;
        DB::transaction(function () use ($animal, $data, $s, $by) {
            SaleClearance::create(['animal_id' => $animal->id, 'decision' => $data['decision'], 'checks' => ['sale' => $s['sale']['code'], 'fattening' => $s['fattening']['note'] ?? null, 'health' => $s['health']['code']],
                'reason' => $data['reason'] ?? null, 'decided_by' => $by, 'decided_on' => now()->toDateString()]);
            Farm::event($animal, 'sale', $data['decision'] === 'cleared' ? 'Cleared for sale: sale ready.' : 'Sale clearance withdrawn'.(! empty($data['reason']) ? ": {$data['reason']}" : '.'), $by);
        });
        Tenant::flush();
        Lifecycle::record($animal->fresh());
        Farm::log($request->user(), ($data['decision'] === 'cleared' ? 'Cleared ' : 'Withdrew sale clearance for ')."{$animal->farm_number}".($data['decision'] === 'cleared' ? ' for sale' : ''), "/goats/{$animal->id}");

        return back()->with('success', $data['decision'] === 'cleared' ? "{$animal->farm_number} is sale ready." : "{$animal->farm_number}: sale clearance withdrawn.");
    }

    public function weights(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sales'), 403);
        $data = $request->validate(['market_weight_male_kg' => ['required', 'numeric', 'between:5,150'], 'market_weight_female_kg' => ['required', 'numeric', 'between:5,150']]);
        \App\Models\Organization::whereKey($request->user()->org_id)->update($data);
        Farm::log($request->user(), "Market weight: males {$data['market_weight_male_kg']} KG, females {$data['market_weight_female_kg']} KG", '/sales/readiness');

        return back()->with('success', 'Market weights saved. A finishing batch\'s own target still wins for its goats.');
    }
}
