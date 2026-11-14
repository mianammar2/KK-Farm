<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\BreedingApproval;
use App\Models\BreedingRule;
use App\Models\Task;
use App\Support\Designations;
use App\Support\Eligibility;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-231: Breeding → Breeding Eligibility. The system checks each candidate against the farm's rules; qualified goats
 * wait for approval; only a person with "Approve goats for breeding" makes a goat a breeder.
 */
class EligibilityController extends Controller
{
    public function index(Request $request): Response
    {
        Eligibility::sync();
        $row = fn (array $r) => ['id' => $r['goat']->id, 'number' => $r['goat']->farm_number, 'name' => $r['goat']->name, 'sex' => $r['goat']->sex, 'breed' => $r['goat']->breed?->name,
            'code' => $r['goat']->classCode(), 'roles' => Designations::codes($r['goat']), 'age' => $r['goat']->ageLabel(), 'status' => $r['status'], 'statusLabel' => $r['label'],
            'review' => $r['review'], 'recommendation' => $r['recommendation'], 'checks' => $r['checks']];
        $rule = fn (BreedingRule $r) => ['id' => $r->id, 'breedId' => $r->breed_id, 'breed' => $r->breed?->name ?? 'All breeds', 'sex' => $r->sex, 'minAge' => $r->min_age_months, 'minWeight' => $r->min_weight_kg,
            'mature' => $r->mature_weight_kg, 'pct' => $r->min_mature_pct, 'bcs' => $r->min_bcs, 'days' => $r->weight_max_age_days];
        Eligibility::rule(null, 'female'); // a farm with no rules yet gets the defaults

        return Inertia::render('Breeding/Eligibility', [
            'rows' => Eligibility::all()->map($row)->values(),
            'rules' => BreedingRule::with('breed')->get()->sortBy(fn ($r) => [$r->breed_id === null ? 0 : 1, $r->breed?->name, $r->sex])->map($rule)->values(),
            'breeds' => Breed::orderBy('name')->get(['id', 'name']),
            'history' => BreedingApproval::with('animal')->latest('decided_on')->latest('id')->limit(30)->get()->map(fn ($a) => ['id' => $a->id, 'goatId' => $a->animal_id, 'number' => $a->animal?->farm_number,
                'decision' => $a->decision, 'on' => $a->decided_on->format('d/m/Y'), 'by' => $a->decided_by, 'override' => $a->override, 'reason' => $a->reason]),
            'canApprove' => $request->user()->passes('approve_breeding'),
        ]);
    }

    /** Approve or reject one goat. Approving one that failed a check is an override and needs a reason. */
    public function decide(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('approve_breeding'), 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])], 'repro_exam_passed' => ['boolean'], 'genetics_approved' => ['boolean'], 'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $check = Eligibility::check($animal);
        if (in_array($check['status'], ['not_candidate', 'breeder', 'approved'], true)) {
            throw ValidationException::withMessages(['decision' => "{$animal->farm_number}: ".strtolower($check['label']).'.']);
        }
        $override = false;
        if ($data['decision'] === 'approved') {
            if (empty($data['repro_exam_passed']) || empty($data['genetics_approved'])) {
                throw ValidationException::withMessages(['repro_exam_passed' => 'Confirm the reproductive examination passed and the line is approved before approving.']);
            }
            $override = $check['status'] !== 'qualified';
            if ($override && mb_strlen(trim((string) ($data['reason'] ?? ''))) < 10) {
                throw ValidationException::withMessages(['reason' => 'Some checks did not pass. Give the reason for approving anyway (at least 10 characters).']);
            }
        }
        $by = $request->user()->name;
        DB::transaction(function () use ($animal, $data, $check, $override, $by) {
            BreedingApproval::create(['animal_id' => $animal->id, 'decision' => $data['decision'], 'checks' => $check['checks'], 'repro_exam_passed' => (bool) ($data['repro_exam_passed'] ?? false),
                'genetics_approved' => (bool) ($data['genetics_approved'] ?? false), 'override' => $override, 'reason' => $data['reason'] ?? null, 'decided_by' => $by, 'decided_on' => now()->toDateString()]);
            if ($data['decision'] === 'approved') {
                $female = $animal->sex === 'female';
                $animal->forceFill(['animal_purpose' => 'breeding', 'animal_class' => $female ? 'breeding_female' : 'breeding_male'] + ($female ? ['repro_status' => 'ready'] : []))->save();
            }
            Task::where('animal_id', $animal->id)->where('title', 'like', Eligibility::TASK_PREFIX.'%')->whereIn('status', Task::OPEN)->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $by]);
            Farm::event($animal, 'breeding', $data['decision'] === 'approved' ? 'Approved for breeding'.($override ? ' (override: '.$data['reason'].')' : '').': ready for breeding.' : 'Not approved for breeding'.(! empty($data['reason']) ? ": {$data['reason']}" : '.'), $by);
        });
        Farm::log($request->user(), ($data['decision'] === 'approved' ? 'Approved ' : 'Did not approve ')."{$animal->farm_number} for breeding", "/goats/{$animal->id}");

        return back()->with('success', $data['decision'] === 'approved'
            ? "{$animal->farm_number} approved: now ".($animal->sex === 'female' ? 'a Breeding Doe (BD), ready for breeding.' : 'a Breeding Buck (BB).')
            : "{$animal->farm_number} not approved.".($animal->sex === 'male' ? ' A fattening assessment is recommended.' : ''));
    }

    /** Save the rule for one breed and sex (or every breed). */
    public function saveRule(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('approve_breeding'), 403);
        $data = $request->validate([
            'breed_id' => ['nullable', 'exists:breeds,id'], 'sex' => ['required', Rule::in(['female', 'male'])],
            'min_age_months' => ['required', 'integer', 'min:1', 'max:60'], 'min_weight_kg' => ['required', 'numeric', 'min:1', 'max:200'],
            'mature_weight_kg' => ['nullable', 'numeric', 'gte:min_weight_kg', 'max:250'], 'min_mature_pct' => ['required', 'integer', 'min:1', 'max:100'],
            'min_bcs' => ['nullable', 'numeric', 'min:1', 'max:5'], 'weight_max_age_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);
        Eligibility::rule(null, 'female');
        BreedingRule::updateOrCreate(['breed_id' => $data['breed_id'] ?? null, 'sex' => $data['sex']], $data);
        $breed = isset($data['breed_id']) ? Breed::find($data['breed_id'])->name : 'all breeds';
        Farm::log($request->user(), "Breeding rule for {$data['sex']}s, {$breed}: {$data['min_age_months']} months, {$data['min_weight_kg']} KG", '/breeding/eligibility');

        return back()->with('success', "Rule saved for {$data['sex']}s, {$breed}. Every candidate is checked again.");
    }

    public function deleteRule(Request $request, BreedingRule $rule): RedirectResponse
    {
        abort_unless($request->user()->passes('approve_breeding') && $rule->breed_id !== null, 403);
        $rule->delete();

        return back()->with('success', 'Breed rule removed: those goats follow the all-breeds rule.');
    }
}
