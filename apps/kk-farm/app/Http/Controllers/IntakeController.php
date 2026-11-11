<?php

namespace App\Http\Controllers;

use App\Models\HealthCase;
use App\Models\Organization;
use App\Support\Farm;
use App\Support\Intake;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** D-172: Intake & Quarantine (Livestock and Veterinary): new arrivals, their checklist and the vet's clearance. */
class IntakeController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Intake', [
            'goats' => Intake::board(), 'days' => Intake::days(),
            // D-190: approved operating pens for the clearance transfer (no pen holding goats still in quarantine).
            'pens' => \App\Models\Pen::with('shed')->where('kind', 'pen')->get()->reject(fn ($p) => \App\Models\Animal::active()->where('pen_id', $p->id)->whereIn('id', Intake::quarantinedIds())->exists())
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->fullName()])->sortBy('name')->values(),
            'canClear' => $request->user()->passes('clinical'), 'canConfigure' => in_array($request->user()->role, ['ceo', 'vet'], true),
        ]);
    }

    public function clear(Request $request, HealthCase $case): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'Only a veterinarian clears a goat from quarantine.');
        abort_unless($case->condition === Intake::CONDITION && in_array($case->status, HealthCase::ACTIVE, true), 422, 'This goat is not in intake quarantine.');
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500'], 'pen_id' => ['nullable', \Illuminate\Validation\Rule::exists('pens', 'id')->where('kind', 'pen')]]);
        \Illuminate\Support\Facades\DB::transaction(function () use ($case, $data, $request) {
            Intake::clear($case, $request->user(), $data['notes'] ?? null);
            // D-190: clearance granted → transfer into the approved operating pen.
            if (! empty($data['pen_id'])) {
                \App\Support\Movements::transfer($case->animal->fresh(), \App\Models\Pen::findOrFail($data['pen_id']), $request->user(), ['reason' => 'Cleared from intake quarantine']);
            }
        });
        Farm::log($request->user(), "Cleared {$case->animal->farm_number} after intake quarantine", "/goats/{$case->animal_id}");

        return back()->with('success', "{$case->animal->farm_number} is cleared and joins the herd".(! empty($data['pen_id']) ? ' in '.\App\Models\Pen::find($data['pen_id'])->fullName() : '').'.');
    }

    /** D-190: clearance not granted → extend quarantine (treat / investigate under the vet), then recheck. */
    public function extend(Request $request, HealthCase $case): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'Only a veterinarian extends quarantine.');
        abort_unless($case->condition === Intake::CONDITION && in_array($case->status, HealthCase::ACTIVE, true), 422, 'This goat is not in intake quarantine.');
        $data = $request->validate(['days' => ['required', 'integer', 'between:1,60'], 'reason' => ['required', 'string', 'max:300']]);
        Intake::extend($case, (int) $data['days'], $data['reason'], $request->user());

        return back()->with('success', "{$case->animal->farm_number}: quarantine extended {$data['days']} days. Recheck before clearance.");
    }

    public function settings(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['ceo', 'vet'], true), 403);
        $data = $request->validate(['quarantine_days' => ['required', 'integer', 'between:1,90']], [], ['quarantine_days' => 'quarantine days']);
        Organization::whereKey(Tenant::current())->update($data);

        return back()->with('success', "Intake quarantine is now {$data['quarantine_days']} days.");
    }
}
