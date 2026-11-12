<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Breed;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /** D-215: the Settings master page (a card per settings page) or one settings page on its own. */
    public function index(Request $request, ?string $section = null): Response
    {
        abort_unless(in_array($section, [null, 'breeds', 'ages', 'system'], true), 404);
        $org = $request->user()->org_id;

        return Inertia::render('Settings', [
            'section' => $section,
            'breeds' => Breed::orderBy('name')->get()->map(fn (Breed $b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code, 'next' => $b->nextTagId(), 'gestation' => $b->default_gestation_days, 'lightBelow' => $b->young_below_kg !== null ? (float) $b->young_below_kg : null, 'goats' => Animal::active()->where('breed_id', $b->id)->count(), 'used' => self::breedUses($b)]),
            'ages' => ['growerFrom' => \App\Support\Classification::boundaries()[0], 'adultFrom' => \App\Support\Classification::boundaries()[1],
                'groups' => $section !== 'ages' ? [] : collect(\App\Support\Classification::AGE_CATEGORIES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'goats' => Animal::active()->get(['birth_date'])->filter(fn ($a) => \App\Support\Classification::ageCategory($a->birth_date) === $key)->count()])->values()],
            'stages' => $section === 'ages' ? self::stageRows() : [],
            'teeth' => $section === 'ages' ? \App\Support\Teeth::stages()->map(fn (\App\Models\TeethStage $t) => ['id' => $t->id, 'key' => $t->key, 'label' => $t->label, 'local' => $t->local_term, 'from' => $t->from_months, 'to' => $t->to_months, 'estimate' => $t->estimate_months,
                'goats' => Animal::where('teeth', $t->key)->count()])->values() : [],
            'counts' => ['users' => \App\Models\User::where('org_id', $org)->where('active', true)->count(), 'fields' => \App\Models\FieldDefinition::count(),
                'overrides' => \App\Models\User::where('org_id', $org)->get()->filter(fn ($u) => ! empty($u->module_access) || ! empty($u->gate_access))->count()],
            'environment' => ['php' => PHP_VERSION, 'laravel' => app()->version(), 'database' => 'PostgreSQL '.explode(' ', \Illuminate\Support\Facades\DB::selectOne('show server_version')->server_version)[0]],
        ]);
    }

    public function addBreed(Request $request): RedirectResponse
    {
        $request->merge(['code' => $request->filled('code') ? Breed::normaliseCode((string) $request->input('code')) : null]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'unique:breeds,name'],
            'code' => ['nullable', 'string', 'min:1', 'max:8', 'unique:breeds,code'], // Tag IDs of this breed start with it (D-041)
        ]);

        Breed::create(array_filter($data) + ['default_gestation_days' => config('kkfarm.demo_gestation_days')]);
        Farm::log($request->user(), "Added breed {$data['name']}", '/settings');

        return back()->with('success', 'Breed added.');
    }

    /** D-110: the breed's "light" weight line, used to estimate the age of goats with no teeth recorded. */
    public function updateBreed(Request $request, Breed $breed): RedirectResponse
    {
        $data = $request->validate(['young_below_kg' => ['nullable', 'numeric', 'between:1,150']]);
        $breed->update(['young_below_kg' => $data['young_below_kg'] ?? null]);
        Farm::log($request->user(), "Set {$breed->name} light weight line to ".($data['young_below_kg'] ?? 'none').' KG', '/settings');

        return back()->with('success', "{$breed->name}: saved. Goats with no teeth get their age from their first weighing.");
    }

    /** D-219: rename a breed or change its tag code. Tags already given keep their ID; new tags use the new code. */
    public function editBreed(Request $request, Breed $breed): RedirectResponse
    {
        $request->merge(['code' => Breed::normaliseCode((string) $request->input('code'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', \Illuminate\Validation\Rule::unique('breeds', 'name')->ignore($breed->id)],
            'code' => ['required', 'string', 'min:1', 'max:8', \Illuminate\Validation\Rule::unique('breeds', 'code')->ignore($breed->id)],
        ]);
        $was = "{$breed->name} ({$breed->code})";
        $breed->update($data);
        Farm::log($request->user(), "Changed breed {$was} to {$data['name']} ({$data['code']})", '/settings/breeds');

        return back()->with('success', "{$data['name']}: saved. Tags already given keep their ID; new tags start with {$data['code']}.");
    }

    /** D-219: delete a breed nothing uses. A breed on any goat (also sold or dead ones) or record stays, so history is kept. */
    public function deleteBreed(Request $request, Breed $breed): RedirectResponse
    {
        $used = self::breedUses($breed);
        if ($used) {
            throw \Illuminate\Validation\ValidationException::withMessages(['breed' => "{$breed->name} cannot be deleted: {$used} goats or records use it. Change their breed first."]);
        }
        $breed->delete();
        Farm::log($request->user(), "Deleted breed {$breed->name} ({$breed->code})", '/settings/breeds');

        return back()->with('success', "{$breed->name} deleted.");
    }

    /** How many rows of any table (goats in every status, purchase lots, plans, standards) point at this breed. */
    public static function breedUses(Breed $breed): int
    {
        $tables = \App\Support\Tenant::memo('breed_id_tables', fn () => collect(\Illuminate\Support\Facades\DB::select("select table_name from information_schema.columns where table_schema = current_schema() and column_name = 'breed_id' and table_name <> 'breeds'"))->pluck('table_name')->all());

        return collect($tables)->sum(fn ($t) => \Illuminate\Support\Facades\DB::table($t)->where('breed_id', $breed->id)->count());
    }

    /** D-221: the age stages with how many active goats are in each today. */
    private static function stageRows(): array
    {
        $codes = Animal::active()->whereNotNull('birth_date')->pluck('birth_date')->countBy(fn ($d) => \App\Support\AgeStages::codeFor($d));

        return \App\Support\AgeStages::stages()->map(fn (\App\Models\AgeStage $s) => ['id' => $s->id, 'code' => $s->code, 'label' => $s->label, 'group' => $s->age_group,
            'at' => $s->starts_at, 'unit' => $s->starts_unit, 'range' => $s->rangeText(), 'goats' => $codes[$s->code] ?? 0])->values()->all();
    }

    /** D-221: add, edit or delete an age stage. Kid / Grower / Adult boundaries follow the stages. */
    public function addStage(Request $request): RedirectResponse
    {
        \App\Support\AgeStages::stages();
        $data = $this->stageData($request);
        \App\Models\AgeStage::create($data);
        \App\Support\AgeStages::syncGroups();
        Farm::log($request->user(), "Added age stage {$data['code']} {$data['label']}", '/settings/ages');

        return back()->with('success', "{$data['code']} added.");
    }

    public function editStage(Request $request, \App\Models\AgeStage $stage): RedirectResponse
    {
        $data = $this->stageData($request, $stage->id);
        $was = $stage->code;
        $stage->update($data);
        \App\Support\AgeStages::syncGroups();
        Farm::log($request->user(), "Changed age stage {$was} to {$data['code']} {$data['label']}", '/settings/ages');

        return back()->with('success', "{$data['code']} saved. Every goat's stage follows its age.");
    }

    public function deleteStage(Request $request, \App\Models\AgeStage $stage): RedirectResponse
    {
        $stage->delete();
        \App\Support\AgeStages::syncGroups();
        Farm::log($request->user(), "Deleted age stage {$stage->code} {$stage->label}", '/settings/ages');

        return back()->with('success', "{$stage->code} deleted.");
    }

    private function stageData(Request $request, ?int $ignore = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:12', 'regex:/^[A-Z0-9-]+$/', \Illuminate\Validation\Rule::unique('age_stages', 'code')->ignore($ignore)],
            'label' => ['required', 'string', 'max:40'],
            'age_group' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Support\Classification::AGE_CATEGORIES))],
            'starts_at' => ['required', 'integer', 'min:0', 'max:3650'],
            'starts_unit' => ['required', \Illuminate\Validation\Rule::in(['days', 'months'])],
        ], ['code.regex' => 'Use capital letters, numbers and dashes, e.g. ADT.']);
        // D-229: a stage runs until the next one starts, so two stages cannot start at the same age.
        $days = $data['starts_unit'] === 'months' ? $data['starts_at'] * 30.4375 : $data['starts_at'];
        $same = \App\Models\AgeStage::when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->get()->first(fn ($s) => abs($s->startDays() - $days) < 1);
        if ($same) {
            throw \Illuminate\Validation\ValidationException::withMessages(['starts_at' => "{$same->code} already starts at {$same->starts_at} {$same->starts_unit}."]);
        }

        return $data;
    }

    /** D-220: add a teeth stage. Its short key never changes, because goats store it; the name can change freely. */
    public function addTeeth(Request $request): RedirectResponse
    {
        $data = $this->teethData($request);
        $key = $data['key']; // D-229: the dental code itself (T10 …); it never changes, because goats store it
        unset($data['key']);
        \App\Support\Teeth::stages(); // a farm with no stages yet gets the defaults first
        \App\Models\TeethStage::create($data + ['key' => $key, 'sort' => $data['from_months'] * 10 + 5]);
        Farm::log($request->user(), "Added teeth stage {$data['label']}", '/settings/ages');

        return back()->with('success', "{$data['label']} added.");
    }

    public function editTeeth(Request $request, \App\Models\TeethStage $stage): RedirectResponse
    {
        $data = $this->teethData($request, $stage->id);
        $stage->update($data);
        Farm::log($request->user(), "Changed teeth stage {$stage->label}", '/settings/ages');

        return back()->with('success', "{$stage->label} saved. Goats already aged keep their birth date; new teeth entries use {$stage->estimate_months} months.");
    }

    /** A teeth stage that any goat (in any status) has stays, so its record keeps its meaning. */
    public function deleteTeeth(Request $request, \App\Models\TeethStage $stage): RedirectResponse
    {
        $used = Animal::where('teeth', $stage->key)->count();
        if ($used) {
            throw \Illuminate\Validation\ValidationException::withMessages(['teeth' => "{$stage->label} cannot be deleted: {$used} goats have it."]);
        }
        $stage->delete();
        Farm::log($request->user(), "Deleted teeth stage {$stage->label}", '/settings/ages');

        return back()->with('success', "{$stage->label} deleted.");
    }

    /** @return array{label: string, from_months: int, to_months: ?int, estimate_months: int} */
    private function teethData(Request $request, ?int $ignore = null): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60', \Illuminate\Validation\Rule::unique('teeth_stages', 'label')->ignore($ignore)],
            'local_term' => ['nullable', 'string', 'max:40'],
            'from_months' => ['required', 'integer', 'min:0', 'max:240'],
            'to_months' => ['nullable', 'integer', 'gt:from_months', 'max:240'],
            'estimate_months' => ['required', 'integer', 'gte:from_months', 'max:240'],
        ]);
        $data['to_months'] ??= null;
        $data['local_term'] ??= null;
        if ($ignore === null) { // a new dental code: capital letters and numbers, not one in use, not UNK
            $request->merge(['key' => strtoupper(trim((string) $request->input('key')))]);
            $data['key'] = $request->validate(['key' => ['required', 'regex:/^[A-Z0-9]{2,8}$/', 'not_in:UNK', \Illuminate\Validation\Rule::unique('teeth_stages', 'key')]], ['key.regex' => 'Use 2 to 8 capital letters or numbers, e.g. T10.'])['key'];
        }
        // D-236: teeth follow age from these ranges, so two codes cannot cover the same months.
        $clash = \App\Models\TeethStage::when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->get()
            ->first(fn ($t) => $data['from_months'] < ($t->to_months ?? INF) && $t->from_months < ($data['to_months'] ?? INF));
        if ($clash) {
            throw \Illuminate\Validation\ValidationException::withMessages(['from_months' => "These months overlap {$clash->key} ({$clash->ageText()}). Teeth follow age, so each month belongs to one code."]);
        }
        if ($data['to_months'] !== null && $data['estimate_months'] > $data['to_months']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['estimate_months' => 'The estimated age must be inside the range.']);
        }

        return $data;
    }

    /** Age category boundaries for this farm (D-047): Kid below the first, Adult from the second, Grower between. */
    public function updateAges(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'grower_from_months' => ['required', 'integer', 'min:1', 'max:24'],
            'adult_from_months' => ['required', 'integer', 'max:60', 'gt:grower_from_months'],
        ]);

        \App\Models\Organization::whereKey($request->user()->org_id)->update($data);
        // D-229: the age stages decide, so the first Grower and first Adult stage move to the new boundaries.
        \App\Support\AgeStages::stages();
        foreach (['grower' => $data['grower_from_months'], 'adult' => $data['adult_from_months']] as $group => $months) {
            \App\Models\AgeStage::where('age_group', $group)->get()->sortBy(fn ($s) => $s->startDays())->first()?->update(['starts_at' => $months, 'starts_unit' => 'months']);
        }
        \App\Support\Classification::forgetBoundaries();
        Farm::log($request->user(), "Set age categories: kid under {$data['grower_from_months']} months, adult from {$data['adult_from_months']} months", '/settings');

        return back()->with('success', 'Age categories saved. Every goat is reclassified from its birth date.');
    }
}
