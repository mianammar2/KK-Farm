<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\ClassificationRule;
use App\Support\AgeStages;
use App\Support\AutoRules;
use App\Support\Classification;
use App\Support\Designations;
use App\Support\Eligibility;
use App\Support\Farm;
use App\Support\Lifecycle;
use App\Support\Teeth;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-236: Settings → Automation Rules. Everything the registry works out by itself, in one place: what the Gender
 * code fills in, the category for each gender × age stage × purpose, the starting female status, kid stages, teeth
 * by age, and links to the breeding and sale rules. A change applies to every goat at once.
 */
class AutomationController extends Controller
{
    public function index(): Response
    {
        $rules = AutoRules::rules();
        $goats = Animal::active()->get();
        $used = $goats->filter(fn ($a) => ! $a->animal_class)->countBy(fn ($a) => ($r = AutoRules::for($a->sex, $a->ageStageCode(), $a->animal_purpose)) ? $r->id : 0);
        $stageOrder = AgeStages::stages()->pluck('code')->flip();

        return Inertia::render('Settings/Automation', [
            'rules' => $rules->sortBy(fn ($r) => [$r->sex === 'female' ? 0 : 1, $stageOrder[$r->stage_code] ?? 99, $r->purpose === 'none' ? 0 : ($r->purpose === '*' ? 2 : 1), $r->purpose])->values()
                ->map(fn ($r) => ['id' => $r->id, 'sex' => $r->sex, 'stage' => $r->stage_code, 'purpose' => $r->purpose, 'class' => $r->animal_class, 'status' => $r->female_status, 'goats' => $used[$r->id] ?? 0,
                    'role' => Designations::FROM_CATEGORY_PUBLIC[$r->animal_class] ?? null, 'fixed' => in_array($r->purpose, ['*', 'none'], true)]),
            'stages' => AgeStages::stages()->map(fn ($s) => ['code' => $s->code, 'label' => $s->label, 'range' => $s->rangeText(), 'group' => $s->age_group])->values(),
            'teeth' => Teeth::stages()->map(fn ($t) => ['code' => $t->key, 'label' => $t->label, 'age' => $t->ageText()])->values(),
            'picks' => collect(Designations::picks(true))->map(fn ($p, $c) => ['value' => $c, 'label' => "{$c} · {$p[3]}", 'name' => $p[3], 'sex' => $p[0], 'purpose' => $p[1], 'class' => $p[2], 'stage' => $p[4],
                'active' => $p[5], 'custom' => ! isset(Designations::PICK[$c]), 'goats' => Animal::where('registered_role', $c)->count()])->values(),
            'categories' => array_map(fn ($c) => $c[0], Classification::CATEGORIES),
            'categorySex' => Classification::categorySex(),
            'statuses' => Classification::FEMALE_STATUSES,
            'purposes' => AutoRules::PURPOSES,
            'goalPurposes' => Classification::PURPOSES,
            'kid' => ['newbornDays' => (int) (AgeStages::stages()->get(1)?->startDays() ?? 15), 'weaningDays' => (int) (Tenant::org()?->weaning_age_days ?? 84)],
            'breeding' => Eligibility::rule(null, 'female')->only(['min_age_months', 'min_weight_kg', 'min_bcs']),
            'market' => Lifecycle::marketWeights(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sex' => ['required', Rule::in(['male', 'female'])], 'stage' => ['required', Rule::in(AgeStages::stages()->pluck('code')->all())],
            'purpose' => ['required', Rule::in(array_keys(AutoRules::PURPOSES))], 'class' => ['required', Rule::in(array_keys(Classification::CATEGORIES))],
            'status' => ['nullable', Rule::in(array_keys(Classification::FEMALE_STATUSES))],
        ]);
        if (($need = Classification::categorySex()[$data['class']] ?? null) && $need !== $data['sex']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['class' => 'That category is for '.($need === 'male' ? 'males' : 'females').' only.']);
        }
        AutoRules::rules();
        ClassificationRule::updateOrCreate(['sex' => $data['sex'], 'stage_code' => $data['stage'], 'purpose' => $data['purpose']],
            ['animal_class' => $data['class'], 'female_status' => $data['sex'] === 'female' ? ($data['status'] ?? null) : null]);
        Farm::log($request->user(), "Automation rule: {$data['sex']} {$data['stage']} {$data['purpose']} → {$data['class']}", '/settings/automation');

        return back()->with('success', 'Rule saved. Every goat without a hand-set category follows it now.');
    }

    /** D-240: add the farm's own gender code. */
    public function addCode(Request $request): RedirectResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate(['code' => ['required', 'regex:/^[A-Z]{2,4}$/', Rule::notIn(array_keys(Designations::picks(true)))], 'sex' => ['required', Rule::in(['male', 'female'])]],
            ['code.regex' => 'Use 2 to 4 capital letters, e.g. QB.', 'code.not_in' => 'That code already exists.']);
        \App\Models\RoleCode::create(['code' => $data['code'], 'sex' => $data['sex'], 'label' => $data['code'], 'animal_class' => $data['sex'] === 'male' ? 'growing_male' : 'growing_female', 'active' => true]);
        Tenant::flush();

        return $this->saveCode($request, $data['code']);
    }

    /** D-240: delete a gender code. One goats were registered with is switched off instead (they keep it). */
    public function deleteCode(Request $request, string $code): RedirectResponse
    {
        $pick = Designations::picks(true)[$code] ?? abort(404);
        $used = Animal::where('registered_role', $code)->count();
        if ($used || isset(Designations::PICK[$code])) {
            \App\Models\RoleCode::updateOrCreate(['code' => $code], ['label' => $pick[3], 'purpose' => $pick[1], 'animal_class' => $pick[2], 'stage_code' => $pick[4], 'sex' => $pick[0], 'active' => false]);
            $msg = $used ? "{$code} is used by {$used} goats: removed from the Gender field, the goats keep it." : "{$code} removed from the Gender field. Add it back any time.";
        } else {
            \App\Models\RoleCode::where('code', $code)->delete();
            $msg = "{$code} deleted.";
        }
        Farm::log($request->user(), "Deleted gender code {$code}", '/settings/automation');

        return back()->with('success', $msg);
    }

    /** D-240: bring back a framework code that was removed. */
    public function restoreCode(Request $request, string $code): RedirectResponse
    {
        \App\Models\RoleCode::where('code', $code)->update(['active' => true]);

        return back()->with('success', "{$code} is back in the Gender field.");
    }

    /** D-239: change what a gender code fills in: its name, purpose, category and starting age. */
    public function saveCode(Request $request, string $code): RedirectResponse
    {
        $pick = Designations::picks(true)[$code] ?? abort(404);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:40'], 'purpose' => ['nullable', Rule::in(array_keys(Classification::PURPOSES))],
            'class' => ['required', Rule::in(array_keys(Classification::CATEGORIES))], 'stage' => ['nullable', Rule::in(AgeStages::stages()->pluck('code')->all())],
        ]);
        if (($need = Classification::categorySex()[$data['class']] ?? null) && $need !== $pick[0]) {
            throw \Illuminate\Validation\ValidationException::withMessages(['class' => "{$code} is a ".($pick[0] === 'male' ? 'male' : 'female').' code: choose a '.($pick[0] === 'male' ? 'male' : 'female').' category.']);
        }
        \App\Models\RoleCode::updateOrCreate(['code' => $code], ['label' => $data['label'], 'purpose' => $data['purpose'] ?? null, 'animal_class' => $data['class'], 'stage_code' => $data['stage'] ?? null, 'sex' => $pick[0], 'active' => $pick[5]]);
        Farm::log($request->user(), "Gender code {$code}: {$data['label']}, ".($data['purpose'] ?? 'no purpose').", {$data['class']}, ".($data['stage'] ?? 'no age'), '/settings/automation');

        return back()->with('success', "{$code} saved. New registrations use it; goats already registered keep what they have.");
    }

    public function delete(Request $request, ClassificationRule $rule): RedirectResponse
    {
        abort_if(in_array($rule->purpose, ['*', 'none'], true), 422, 'The base rows stay; change them instead.');
        $rule->delete();
        Farm::log($request->user(), "Removed automation rule {$rule->sex} {$rule->stage_code} {$rule->purpose}", '/settings/automation');

        return back()->with('success', 'Rule removed: those goats follow the stage\'s "any other purpose" row.');
    }
}
