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
            'canClear' => $request->user()->passes('clinical'), 'canConfigure' => in_array($request->user()->role, ['ceo', 'vet'], true),
        ]);
    }

    public function clear(Request $request, HealthCase $case): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'Only a veterinarian clears a goat from quarantine.');
        abort_unless($case->condition === Intake::CONDITION && in_array($case->status, HealthCase::ACTIVE, true), 422, 'This goat is not in intake quarantine.');
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        Intake::clear($case, $request->user(), $data['notes'] ?? null);
        Farm::log($request->user(), "Cleared {$case->animal->farm_number} after intake quarantine", "/goats/{$case->animal_id}");

        return back()->with('success', "{$case->animal->farm_number} is cleared and joins the herd.");
    }

    public function settings(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['ceo', 'vet'], true), 403);
        $data = $request->validate(['quarantine_days' => ['required', 'integer', 'between:1,90']], [], ['quarantine_days' => 'quarantine days']);
        Organization::whereKey(Tenant::current())->update($data);

        return back()->with('success', "Intake quarantine is now {$data['quarantine_days']} days.");
    }
}
