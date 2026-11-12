<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\AnimalPhoto;
use App\Models\Breed;
use App\Models\FeedingGroup;
use App\Models\Kidding;
use App\Models\LedgerEntry;
use App\Models\Mating;
use App\Models\Pen;
use App\Models\RfidAssignment;
use App\Models\FieldDefinition;
use App\Support\Classification;
use App\Support\FieldCatalog;
use App\Support\Farm;
use App\Support\GoatRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GoatController extends Controller
{
    public function index(): Response
    {
        $goats = Animal::with(['breed', 'pen.shed', 'latestWeight', 'photos', 'openAssignment.tag'])->orderBy('farm_number')->get();
        $tags = \App\Support\HerdStats::tagsFor($goats); // D-071: the dashboard links here with ?show=KEY

        return Inertia::render('Goats/Index', [
            'goats' => $goats->map(fn (Animal $a) => $a->card() + ['tag' => $a->openAssignment?->tag?->tag_code, 'tags' => $tags[$a->id]]),
            'showLabels' => \App\Support\HerdStats::labels(),
            'teethOptions' => \App\Support\Teeth::options(), 'teethShort' => \App\Support\Teeth::short(), 'canEdit' => request()->user()->passes('edit_goats'), 'canTag' => request()->user()->passes('assign_tags'), // D-106, D-144
            'breeds' => Breed::orderBy('name')->pluck('name', 'id'),
            'pens' => Pen::with('shed')->where('kind', 'pen')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName(), 'purpose' => $p->purpose])->sortBy('name')->values(),
        ]);
    }

    /**
     * The Goat Register form (D-040). Opened from an RFID scan with the scanned code, or by hand.
     * The fields, their order and their dropdown options come from the organization's field definitions.
     */
    public function register(Request $request): Response
    {
        $epc = GoatRegistration::normaliseCode($request->query('epc'));

        return Inertia::render('Goats/Register', ['epc' => $epc !== '' ? $epc : null] + $this->formProps($request));
    }

    /** D-091: the same form as the register, filled with the goat's details. Every change is kept in its history. */
    public function edit(Request $request, Animal $animal): Response
    {
        abort_unless($request->user()->passes('edit_goats'), 403);

        return Inertia::render('Goats/Register', ['epc' => null, 'goat' => [
            'id' => $animal->id, 'number' => $animal->farm_number,
            'values' => [
                'origin' => $animal->origin, 'breed_id' => (string) $animal->breed_id, 'acquired_on' => $animal->acquired_on?->format('Y-m-d'), 'tag_id' => $animal->farm_number,
                'sex' => $animal->sex, 'role_code' => $animal->registered_role ?? \App\Support\Designations::main($animal), 'name' => $animal->name, 'source_farm' => $animal->source_farm, 'purchase_cost_pkr' => $animal->purchase_cost_minor !== null ? $animal->purchase_cost_minor / 100 : null,
                'teeth' => $animal->teeth, 'birth_date' => $animal->birth_date?->format('Y-m-d'), 'birth_weight_kg' => $animal->birth_weight_kg,
                'sire_id' => $animal->sire_id, 'dam_id' => $animal->dam_id, 'sire_tag' => $animal->sire_tag, 'dam_tag' => $animal->dam_tag,
                'breed_purity' => $animal->breed_purity, 'cross_breeds' => $animal->cross_breeds, 'age_category' => $animal->age_category, 'animal_purpose' => $animal->animal_purpose,
                'animal_class' => $animal->animal_class, 'repro_status' => $animal->repro_status, 'kid_stage' => $animal->kid_stage, 'health_flag' => $animal->health_flag,
                'pen_id' => (string) $animal->pen_id, 'ownership' => $animal->ownership, 'notes' => $animal->notes, 'custom_fields' => $animal->custom_fields ?: (object) [],
            ],
        ]] + $this->formProps($request));
    }

    public function update(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('edit_goats'), 403);
        if ($request->filled('tag_id')) {
            $request->merge(['tag_id' => strtoupper(trim((string) $request->input('tag_id')))]);
        }
        $defs = FieldCatalog::forAnimal()->filter(fn (FieldDefinition $f) => ! $f->builtin || in_array($f->key, GoatRegistration::EDITABLE, true));
        $rules = GoatRegistration::rules($defs);
        $rules['tag_id'] = ['required', 'string', 'max:40'];
        $data = $request->validate($rules);
        $changes = GoatRegistration::update($animal, $data, $request->user());
        if (isset($data['custom_fields']) && $data['custom_fields'] != ($animal->custom_fields ?? [])) {
            $animal->update(['custom_fields' => $data['custom_fields']]);
            Farm::event($animal, 'edit', 'Edited: extra fields', $request->user()->name);
            $changes[] = 'extra fields';
        }

        return redirect("/goats/{$animal->id}")->with('success', $changes ? 'Saved: '.count($changes).' change'.(count($changes) === 1 ? '' : 's').'. The old values stay in the goat\'s history.' : 'Nothing changed.');
    }

    /**
     * D-091: a goat entered by mistake is removed in two steps. Someone asks, with a reason; the owner or a manager
     * confirms. Nothing is deleted: the goat is marked removed (out of every count, list and plan), its tag is freed,
     * and its history shows who asked, who confirmed, when and why. It can be restored.
     */
    public function requestRemoval(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('edit_goats'), 403);
        abort_if($animal->status === 'voided', 422, 'This goat is already removed.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']], [], ['reason' => 'reason']);
        $animal->update(['removal_requested_by' => $request->user()->name, 'removal_requested_at' => now(), 'removal_reason' => $data['reason']]);
        Farm::event($animal, 'edit', "Removal asked by {$request->user()->name}: {$data['reason']}", $request->user()->name);
        Farm::log($request->user(), "Asked to remove goat {$animal->farm_number} (wrong entry)", "/goats/{$animal->id}");

        return back()->with('success', $request->user()->passes('remove_goats') ? 'Removal asked. Confirm it below to remove the goat.' : 'Removal asked. The owner or a manager confirms it.');
    }

    public function decideRemoval(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('remove_goats'), 403);
        abort_unless($animal->removal_requested_at && $animal->status !== 'voided', 422, 'There is no removal to decide.');
        $data = $request->validate(['decision' => ['required', Rule::in(['confirm', 'cancel'])]]);
        $by = $request->user()->name;
        if ($data['decision'] === 'cancel') {
            Farm::event($animal, 'edit', "Removal cancelled by $by (asked by {$animal->removal_requested_by})", $by);
            $animal->update(['removal_requested_by' => null, 'removal_requested_at' => null, 'removal_reason' => null]);

            return back()->with('success', 'Removal cancelled. The goat stays.');
        }
        DB::transaction(function () use ($animal, $request, $by) {
            Farm::closeOpenTag($animal, 'removed', $request->user());
            $animal->update(['status_before_void' => $animal->status, 'status' => 'voided', 'voided_by' => $by, 'voided_at' => now()]);
            Farm::event($animal, 'edit', "Removed as a wrong entry. Asked by {$animal->removal_requested_by}, confirmed by $by: {$animal->removal_reason}", $by);
        });
        Farm::log($request->user(), "Removed goat {$animal->farm_number} (wrong entry)", "/goats/{$animal->id}");

        return redirect('/goats')->with('success', "{$animal->farm_number} removed. It stays on record and can be restored from its page.");
    }

    public function restore(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('remove_goats'), 403);
        abort_unless($animal->status === 'voided', 422);
        $animal->update(['status' => $animal->status_before_void ?: 'active', 'status_before_void' => null, 'removal_requested_by' => null, 'removal_requested_at' => null, 'removal_reason' => null, 'voided_by' => null, 'voided_at' => null]);
        Farm::event($animal, 'edit', "Restored by {$request->user()->name}", $request->user()->name);

        return back()->with('success', "{$animal->farm_number} restored. Put a tag on it again from the scan screen if needed.");
    }

    /**
     * D-106: set the teeth from the Goat Registry. For a goat whose birth date is not known exactly (bought,
     * transferred, or never set) the estimated birth date follows the teeth, so the age shows up to today. Kept in
     * the goat's history like any edit.
     */
    /**
     * D-230: the vet records a castration that was done. The goat becomes a wether from that day and can no longer be
     * chosen as a sire; a breeding purpose is cleared (the manager assigns a new one). A recovery check is due in 7 days.
     */
    public function castration(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403);
        if ($animal->sex !== 'male' || $animal->castrated_on) {
            throw \Illuminate\Validation\ValidationException::withMessages(['castrated_on' => $animal->castrated_on ? 'Already recorded as castrated.' : 'Only a male goat can be castrated.']);
        }
        $data = $request->validate(['castrated_on' => ['required', 'date', 'before_or_equal:today'], 'method' => ['required', Rule::in(array_keys(self::CASTRATION_METHODS))], 'notes' => ['nullable', 'string', 'max:500']]);
        $on = \Illuminate\Support\Carbon::parse($data['castrated_on']);
        \Illuminate\Support\Facades\DB::transaction(function () use ($animal, $data, $on, $request) {
            \App\Models\HealthRecord::create(['animal_id' => $animal->id, 'kind' => 'procedure', 'occurred_on' => $on, 'title' => 'Castration ('.self::CASTRATION_METHODS[$data['method']].')',
                'notes' => $data['notes'] ?? null, 'next_due_on' => $on->copy()->addDays(7), 'recorded_by' => $request->user()->name, 'recorded_role' => $request->user()->roleLabel(), 'status' => 'open']);
            $animal->forceFill(['castrated_on' => $on->toDateString(), 'animal_purpose' => in_array($animal->animal_purpose, ['breeding', 'replacement'], true) ? null : $animal->animal_purpose])->save();
            Farm::event($animal, 'health', 'Castrated ('.self::CASTRATION_METHODS[$data['method']].'): now a wether, breeding blocked. Recovery check due '.$on->copy()->addDays(7)->format('j M').'.', $request->user()->name, $on);
        });
        Farm::log($request->user(), "Castration recorded for {$animal->farm_number}", "/goats/{$animal->id}");

        return back()->with('success', "{$animal->farm_number} recorded as castrated: now a wether (WTH) and cannot be used as a sire.");
    }

    public const CASTRATION_METHODS = ['band' => 'Rubber ring / band', 'burdizzo' => 'Burdizzo (bloodless)', 'surgical' => 'Surgical'];

    public function teeth(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('edit_goats'), 403);
        $data = $request->validate(['teeth' => ['nullable', Rule::in(array_keys(\App\Support\Teeth::options()))], // D-229: an examination: dental code + condition
            'dental_condition' => ['nullable', Rule::in(array_keys(\App\Support\Teeth::CONDITIONS))], 'examined_on' => ['nullable', 'date', 'before_or_equal:today']]);
        $animal->forceFill(['dental_condition' => $data['dental_condition'] ?? $animal->dental_condition, 'teeth_checked_on' => $data['examined_on'] ?? now()->toDateString()]);
        $exact = $animal->birth_date && ! $animal->birth_date_is_estimate && $animal->origin === 'born_on_farm';
        $values = ['origin' => $animal->origin, 'breed_id' => $animal->breed_id, 'tag_id' => $animal->farm_number, 'sex' => $animal->sex, 'pen_id' => $animal->pen_id, 'teeth' => $data['teeth'] ?? null,
            'birth_date' => ! $exact && ! empty($data['teeth']) ? now()->subMonths(\App\Support\Teeth::months()[$data['teeth']])->toDateString() : $animal->birth_date?->toDateString()];
        $changes = GoatRegistration::update($animal, $values, $request->user());
        if ($animal->isDirty('dental_condition')) { // D-229: same teeth, new condition is still a new check
            $animal->save();
            $changes = $changes ?: ['dental_condition'];
        }

        return back()->with('success', $changes ? "{$animal->farm_number}: teeth saved".($exact ? '.' : ', age estimated from them.') : 'Nothing changed.');
    }

    /** @return array<string, mixed> what the register and edit form need */
    private function formProps(Request $request): array
    {
        return [
            'fields' => FieldCatalog::forAnimal()->map(fn (FieldDefinition $f) => [
                'key' => $f->key, 'label' => $f->label, 'type' => $f->type, 'section' => $f->section, 'help' => $f->help, 'builtin' => $f->builtin,
                'required' => $f->required || in_array($f->key, FieldCatalog::STRUCTURAL, true),
                'options' => $f->type !== 'select' ? null : ($f->key === 'teeth' ? collect($f->optionMap())->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values() : $f->options), // D-220
            ])->values(),
            'lookups' => [
                'breed_id' => Breed::orderBy('name')->get()->map(fn (Breed $b) => ['value' => $b->id, 'label' => $b->name, 'next' => $b->nextTagId(), 'code' => $b->code, 'nextNumber' => $b->last_number + 1]),
                // Shed and pen are two linked dropdowns: choosing a shed lists only its pens. Planned sheds are not offered.
                'pen_id' => Pen::with('shed')->whereHas('shed', fn ($q) => $q->where('status', 'active'))->orderBy('name')->get()
                    ->map(fn (Pen $p) => ['value' => $p->id, 'label' => $p->fullName().($p->purpose ? " · {$p->purpose}" : ''), 'shed' => $p->shed_id, 'shedName' => trim($p->shed?->name.($p->shed?->typeName() ? ' · '.$p->shed->typeName() : ''))]),
                'sire_id' => Animal::where('sex', 'male')->orderBy('farm_number')->get(['id', 'farm_number', 'name', 'breed_id'])->map(fn ($a) => ['value' => $a->id, 'label' => $a->farm_number.($a->name ? " · {$a->name}" : ''), 'breed' => $a->breed_id, 'code' => $a->farm_number]),
                'dam_id' => Animal::where('sex', 'female')->orderBy('farm_number')->get(['id', 'farm_number', 'name', 'breed_id'])->map(fn ($a) => ['value' => $a->id, 'label' => $a->farm_number.($a->name ? " · {$a->name}" : ''), 'breed' => $a->breed_id, 'code' => $a->farm_number]),
            ],
            'teethMonths' => \App\Support\Teeth::months(), // D-220
            'teethRanges' => \App\Support\Teeth::stages()->map(fn ($t) => ['code' => $t->key, 'label' => $t->label, 'from' => $t->from_months, 'to' => $t->to_months])->values(), // D-237: teeth follow age on the form
            'ageStages' => \App\Support\AgeStages::stages()->map(fn ($s) => ['code' => $s->code, 'label' => $s->label, 'group' => $s->age_group, 'at' => $s->starts_at, 'unit' => $s->starts_unit, 'range' => $s->rangeText(), 'estimate' => round($s->estimateMonths(), 1)])->values(), // D-229
            'classSex' => GoatRegistration::classSex(),
            'roleOptions' => array_values(\App\Support\Designations::pickOptions()), // D-235: the Gender field
            'classification' => [
                'categories' => array_map(fn (array $c) => $c[0], Classification::CATEGORIES),
                'statuses' => Classification::FEMALE_STATUSES, 'purposes' => Classification::PURPOSES, 'kidStages' => Classification::KID_STAGES,
                'growerFrom' => Classification::boundaries()[0], 'adultFrom' => Classification::boundaries()[1],
            ],
            // D-042: for each doe, the latest mating a kid could come from, so choosing the dam fills in the sire.
            'damMatings' => Mating::with('sire')->whereIn('status', ['open', 'pregnant', 'kidded'])->where('mated_on', '>=', now()->subDays(GoatRegistration::GESTATION_WINDOW[1] + 30))
                ->orderByRaw("CASE status WHEN 'pregnant' THEN 0 WHEN 'kidded' THEN 1 ELSE 2 END")->orderByDesc('mated_on')->get()->unique('dam_id')
                ->mapWithKeys(fn (Mating $m) => [$m->dam_id => [
                    'sireId' => $m->sire_id, 'sire' => $m->sire?->farm_number, 'sireBreed' => $m->sire?->breed_id, 'status' => $m->status,
                    'matedOn' => $m->mated_on->format('j M Y'), 'expected' => $m->expected_kidding_on?->format('Y-m-d'), 'expectedLabel' => $m->expected_kidding_on?->format('j M Y'),
                ]]),
            'mixedBreedId' => Breed::whereIn('name', ['Crossbreed', 'Mixed'])->value('id'),
            'canAssignTags' => $request->user()->passes('assign_tags'),
            'untagged' => Animal::active()->whereDoesntHave('openAssignment')->orderBy('farm_number')->get(['id', 'farm_number', 'name']),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        // Tag IDs are stored upper-case, the same as generated ones (BT-0001), so duplicates are caught either way.
        if ($request->filled('tag_id')) {
            $request->merge(['tag_id' => strtoupper(trim((string) $request->input('tag_id')))]);
        }
        $data = $request->validate(GoatRegistration::rules(FieldCatalog::forAnimal()), [
            'temperature_f.required' => 'Take her body temperature (°F): it is required for a pregnant goat.', // D-093
            'pregnancy_days.required_if' => 'About how many days pregnant is she?',
        ], ['temperature_f' => 'body temperature']);

        $animal = GoatRegistration::register($data, $request->user(), $request->boolean('from_scan') ? 'scan' : 'manual');
        $tag = $animal->openAssignment()->with('tag')->first()?->tag?->tag_code;

        return redirect("/goats/{$animal->id}")->with('success', $tag ? "{$animal->farm_number} registered with tag $tag." : "{$animal->farm_number} registered. Assign an RFID tag from the scan screen.");
    }

    public function show(Request $request, Animal $animal): Response
    {
        $animal->load(['breed', 'pen.shed', 'feedingGroup', 'latestWeight', 'photos', 'sire.breed', 'dam.breed', 'sire.sire', 'sire.dam', 'dam.sire', 'dam.dam']);
        $weights = $animal->weights()->get();
        $offspring = Animal::with(['breed', 'pen', 'latestWeight', 'photos'])->where($animal->sex === 'male' ? 'sire_id' : 'dam_id', $animal->id)->orderBy('birth_date')->get();
        $canFinance = $request->user()->canModule('finance');
        $weightDays = $weights->count() > 1 ? max(1, $weights->first()->measured_on->diffInDays($weights->last()->measured_on)) : null;

        return Inertia::render('Goats/Show', [
            'goat' => $animal->card() + [
                'origin' => $animal->origin, 'birthDate' => $animal->birth_date?->format('j M Y'), 'estimated' => $animal->birth_date_is_estimate, 'height' => $animal->height_cm !== null ? round((float) $animal->height_cm / 2.54, 1) : null, // inches (D-065)
                'shed' => $animal->pen?->shed?->name, 'penPurpose' => $animal->pen?->purpose, 'feedingGroup' => $animal->feedingGroup?->name ?? FeedingGroup::where('code', \App\Support\Feed::groupCode($animal))->value('name'), // D-054: automatic group
                'withdrawal' => \App\Support\Withdrawal::summary($animal), // D-073
                'exitReason' => $animal->exit_reason, 'exitDate' => $animal->exit_date?->format('j M Y'), 'adg' => Farm::adg($weights),
                'lifetimeAdg' => $weightDays ? (int) round(($weights->last()->weight_kg - $weights->first()->weight_kg) * 1000 / $weightDays) : null,
                'identity' => $animal->id,
                'removal' => $animal->removal_requested_at || $animal->status === 'voided' ? ['by' => $animal->removal_requested_by, 'on' => $animal->removal_requested_at?->format('j M Y g:i A'), 'reason' => $animal->removal_reason, 'voidedBy' => $animal->voided_by, 'voidedOn' => $animal->voided_at?->format('j M Y g:i A')] : null, // D-091
                'teeth' => $animal->teeth ? (\App\Support\Teeth::options()[$animal->teeth] ?? $animal->teeth) : null,
                'ageStage' => ($st = \App\Support\AgeStages::forBirth($animal->birth_date, $animal->exit_date)) ? ['code' => $st->code, 'label' => $st->label, 'range' => $st->rangeText()] : null, // D-229
                'dental' => ['code' => \App\Support\Teeth::code($animal->teeth), 'condition' => \App\Support\Teeth::CONDITIONS[$animal->dental_condition] ?? null, 'checkedOn' => $animal->teeth_checked_on?->format('j M Y'),
                    'history' => $animal->dentalExams()->limit(10)->get()->map(fn ($e) => ['on' => $e->examined_on->format('j M Y'), 'code' => \App\Support\Teeth::code($e->dental_code), 'condition' => \App\Support\Teeth::CONDITIONS[$e->condition] ?? null, 'by' => $e->recorded_by, 'source' => $e->source])->all()],
                'classification' => $animal->classification(),
                'sire' => $animal->sire ? ['id' => $animal->sire->id, 'number' => $animal->sire->farm_number] : ($animal->sire_tag ? ['id' => null, 'number' => $animal->sire_tag] : null),
                'dam' => $animal->dam ? ['id' => $animal->dam->id, 'number' => $animal->dam->farm_number] : ($animal->dam_tag ? ['id' => null, 'number' => $animal->dam_tag] : null),
            ],
            'photos' => $animal->photos->map(fn (AnimalPhoto $p) => ['id' => $p->id, 'notes' => $p->notes, 'isProfile' => $p->is_profile, 'by' => $p->added_by, 'on' => $p->created_at->format('j M Y')]),
            'tags' => RfidAssignment::with('tag')->where('animal_id', $animal->id)->orderByDesc('valid_from')->get()
                ->map(fn (RfidAssignment $r) => ['code' => $r->tag->tag_code, 'from' => $r->valid_from->format('j M Y'), 'to' => $r->valid_to?->format('j M Y'), 'reason' => $r->reason_assigned, 'closed' => $r->reason_unassigned, 'by' => $r->assigned_by]),
            'weights' => $weights->map(fn ($w) => ['on' => $w->measured_on->format('Y-m-d'), 'kg' => $w->weight_kg]),
            'timeline' => $animal->events()->limit(60)->get()->map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'summary' => $e->summary, 'by' => $e->recorded_by, 'on' => $e->occurred_at->format('j M Y')]),
            // D-073: health cases with their treatment doses
            'cases' => \App\Models\HealthCase::with('treatments')->where('animal_id', $animal->id)->orderByDesc('onset_on')->get()->map(fn ($c) => [
                'id' => $c->id, 'label' => $c->label(), 'condition' => $c->condition, 'status' => \App\Models\HealthCase::STATUSES[$c->status], 'active' => in_array($c->status, \App\Models\HealthCase::ACTIVE, true),
                'severity' => \App\Models\HealthCase::SEVERITIES[$c->severity], 'onset' => $c->onset_on->format('j M Y'), 'resolved' => $c->resolved_on?->format('j M Y'), 'diagnosis' => $c->diagnosis,
                'treatments' => $c->treatments->map(fn ($t) => ['id' => $t->id, 'on' => $t->given_on->format('j M'), 'text' => "{$t->product} {$t->dose}", 'meat' => $t->meat_clear_on?->format('j M Y'), 'milk' => $t->milk_clear_on?->format('j M Y'), 'by' => $t->recorded_by])->values(),
            ]),
            'health' => $animal->healthRecords()->limit(40)->get()->map(fn ($h) => ['id' => $h->id, 'kind' => $h->kind, 'title' => $h->title, 'notes' => $h->notes, 'severity' => $h->severity, 'by' => $h->recorded_by, 'role' => $h->recorded_role, 'on' => $h->occurred_on->format('j M Y'), 'next' => $h->next_due_on?->format('j M Y'), 'status' => $h->status]),
            'pedigree' => Farm::pedigree($animal),
            'offspring' => $offspring->map(fn (Animal $a) => $a->card()),
            'matings' => Mating::with(['sire', 'dam'])->where($animal->sex === 'male' ? 'sire_id' : 'dam_id', $animal->id)->orderByDesc('mated_on')->limit(12)->get()
                ->map(fn (Mating $m) => ['id' => $m->id, 'on' => $m->mated_on->format('j M Y'), 'partner' => ($animal->sex === 'male' ? $m->dam : $m->sire)?->farm_number, 'status' => $m->status, 'expected' => $m->expected_kidding_on?->format('j M Y')]),
            'kiddings' => Kidding::where('dam_id', $animal->id)->orderByDesc('kidded_on')->get()->map(fn (Kidding $k) => ['id' => $k->id, 'on' => $k->kidded_on->format('j M Y'), 'alive' => $k->born_alive, 'stillborn' => $k->stillborn]),
            'cost' => $canFinance ? $this->illustrativeCost($animal) : null,
            'castration' => $animal->sex === 'male' ? ['on' => $animal->castrated_on?->format('j M Y'), 'methods' => self::CASTRATION_METHODS, 'can' => $request->user()->passes('clinical')] : null, // D-230
            'statuses' => $animal->status === 'active' ? (function () use ($animal) { \App\Support\Lifecycle::record($animal); return \App\Support\Lifecycle::statuses($animal); })() : null, // D-232
            'statusHistory' => \App\Models\StatusHistory::where('animal_id', $animal->id)->whereNotNull('from_status')->latest('changed_at')->latest('id')->limit(20)->get()
                ->map(fn ($h) => ['dimension' => $h->dimension, 'from' => \App\Support\Lifecycle::LABELS[$h->dimension][$h->from_status] ?? $h->from_status, 'to' => \App\Support\Lifecycle::LABELS[$h->dimension][$h->to_status] ?? $h->to_status, 'rule' => $h->rule, 'at' => $h->changed_at->format('j M Y g:i A')]),
            'eligibility' => ($e = \App\Support\Eligibility::check($animal))['status'] === 'not_candidate' ? null : ['status' => $e['status'], 'label' => $e['label'], 'review' => $e['review'], 'recommendation' => $e['recommendation'],
                'failed' => collect($e['checks'])->filter(fn ($c) => ! $c['ok'])->pluck('label')->values()], // D-231
            'roleLabels' => \App\Support\Designations::labels(\App\Support\Designations::codes($animal)),
            'dentalOptions' => ['codes' => \App\Support\Teeth::options(), 'conditions' => \App\Support\Teeth::CONDITIONS], // D-229
            'can' => ['edit' => $request->user()->passes('edit_goats'), 'remove' => $request->user()->passes('remove_goats'), 'breeding' => $request->user()->canModule('breeding')],
            'pregnancy' => $animal->sex === 'female' ? \App\Support\Checkups::pregnancyThread($animal) : null, // D-100
            'outcomeOptions' => \App\Models\Kidding::formOptions(), // D-161
            // D-166: the doe's open pregnancy for an ultrasound by the vet
            'scanMating' => $animal->sex === 'female' && $request->user()->passes('clinical') && ($sm = \App\Models\Mating::where('dam_id', $animal->id)->whereIn('status', ['open', 'pregnant', 'inconclusive'])->latest('mated_on')->first())
                ? ['id' => $sm->id, 'matedOnIso' => $sm->mated_on->toDateString(), 'day' => (int) $sm->mated_on->diffInDays(now())] : null,
            'ultrasoundOptions' => \App\Models\PregnancyCheck::formOptions(),
            'milking' => $animal->sex === 'female' ? $this->milking($animal) : null, // D-117
            'healthEntry' => $animal->status === 'active' && $request->user()->canModule('health') ? \App\Support\Checkups::entryData($request->user(), new \Illuminate\Database\Eloquent\Collection([$animal])) : null, // D-102
            'pens' => Pen::with('shed')->where('kind', 'pen')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName(), 'purpose' => $p->purpose])->sortBy('name')->values(),
        ]);
    }

    public function addPhoto(Request $request, Animal $animal): RedirectResponse
    {
        $data = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:15360'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);

        $path = $data['photo']->store("animals/{$animal->id}");
        abort_unless($path, 500, 'The photograph could not be stored.');

        DB::transaction(function () use ($animal, $path, $data, $request): void {
            $isFirst = ! $animal->photos()->exists();
            AnimalPhoto::create(['animal_id' => $animal->id, 'path' => $path, 'notes' => $data['notes'] ?? null, 'is_profile' => $isFirst, 'added_by' => $request->user()->name]);
            Farm::event($animal, 'photo', 'Photograph added', $request->user()->name);
        });

        return back()->with('success', 'Photograph saved to this goat.');
    }

    public function setProfilePhoto(Animal $animal, AnimalPhoto $photo): RedirectResponse
    {
        abort_unless($photo->animal_id === $animal->id, 404);
        DB::transaction(function () use ($animal, $photo): void {
            AnimalPhoto::where('animal_id', $animal->id)->update(['is_profile' => false]);
            $photo->update(['is_profile' => true]);
        });

        return back()->with('success', 'Profile picture updated.');
    }

    public function media(AnimalPhoto $photo): BinaryFileResponse
    {
        abort_unless(Storage::exists($photo->path), 404);

        return response()->file(Storage::path($photo->path), ['Cache-Control' => 'private, max-age=86400']);
    }

    /**
     * D-080: correct a goat's birth date (e.g. the purchase date was entered as the birth date). The change is written
     * to the goat's lifecycle with the old date and the reason, so the history shows what was corrected and by whom.
     */
    public function correctBirth(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['ceo', 'manager'], true), 403);
        $data = $request->validate([
            'birth_date' => ['nullable', 'required_without:age_months', 'date', 'before_or_equal:today'], 'age_months' => ['nullable', 'integer', 'between:0,240'],
            'estimated' => ['boolean'], 'reason' => ['required', 'string', 'max:200'],
        ], [], ['age_months' => 'age in months']);
        $new = ! empty($data['birth_date']) ? \Illuminate\Support\Carbon::parse($data['birth_date']) : now()->subMonths((int) $data['age_months']);
        $old = $animal->birth_date?->format('j M Y') ?? 'none';
        $animal->update(['birth_date' => $new->toDateString(), 'birth_date_is_estimate' => (bool) ($data['estimated'] ?? empty($data['birth_date'])), 'age_category' => null]);
        Farm::event($animal, 'origin', "Birth date corrected from {$old} to {$new->format('j M Y')}: {$data['reason']}", $request->user()->name);
        Farm::log($request->user(), "Corrected the birth date of {$animal->farm_number}", "/goats/{$animal->id}");

        return back()->with('success', "Birth date of {$animal->farm_number} is now {$new->format('j M Y')}.");
    }

    /** The goat page's "Record exit": one movement through the movement service (D-076). */
    public function exitFarm(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('exit_animal'), 403);
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(\App\Support\Movements::REASON_TO_TYPE))],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'], 'destination' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        abort_unless($animal->status === 'active', 422);
        try {
            \App\Support\Movements::exit($animal, \App\Support\Movements::REASON_TO_TYPE[$data['reason']], $request->user(), $data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['reason' => collect($e->errors())->flatten()->first()]);
        }
        Farm::log($request->user(), "Closed {$animal->farm_number} ({$data['reason']})", "/goats/{$animal->id}");

        return back()->with('success', 'Exit recorded. The tag is released and every record stays on this goat.');
    }

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            if (config('kkfarm.demo')) { // D-228: only the demo install says so; the live farm's export is its real register
                fputcsv($out, ['Synthetic demonstration data']);
            }
            fputcsv($out, ['Number', 'Name', 'Sex', 'Breed', 'Born', 'Classification code', 'Age stage', 'Teeth', 'Dental condition', 'Role codes', 'Pen', 'Status', 'Health', 'Latest weight (KG)']); // D-227: age stage code
            Animal::with(['breed', 'pen', 'latestWeight'])->orderBy('farm_number')->each(function (Animal $a) use ($out): void {
                fputcsv($out, [$a->farm_number, $a->name, $a->sex, $a->breed?->name, $a->birth_date?->toDateString(), $a->classCode(), $a->ageStageCode(), \App\Support\Teeth::code($a->teeth), \App\Support\Teeth::CONDITIONS[$a->dental_condition] ?? null, implode(' ', \App\Support\Designations::codes($a)), $a->pen?->fullName(), $a->status, $a->health_status, $a->latestWeight?->weight_kg]);
            });
            fclose($out);
        }, 'kk-farm-goat-register-demo.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Direct entries plus an equal per-head share of feed and medicine. The real rule is not decided (Q-FN-2),
     * so the page labels this figure illustrative.
     *
     * @return array{direct: float, sharedPerHead: float, total: float}
     */
    private function illustrativeCost(Animal $animal): array
    {
        $direct = (float) LedgerEntry::where('animal_id', $animal->id)->where('direction', 'out')->sum('amount');
        $shared = (float) LedgerEntry::whereIn('category', ['Feed', 'Medicine'])->where('direction', 'out')->where('occurred_on', '>=', now()->subMonths(12))->sum('amount');
        $share = round($shared / max(1, Animal::active()->count()));

        return ['direct' => $direct, 'sharedPerHead' => $share, 'total' => $direct + $share];
    }

    /** D-117: the doe's lactations: the one in milk now (days, 7-day average, total, standard) and earlier ones. */
    private function milking(Animal $animal): ?array
    {
        $lactations = \App\Models\Lactation::where('animal_id', $animal->id)->orderByDesc('number')->get();
        if ($lactations->isEmpty()) {
            return null;
        }
        $totals = \App\Models\MilkRecord::current()->where('animal_id', $animal->id)->selectRaw('lactation_id, sum(litres) as l')->groupBy('lactation_id')->pluck('l', 'lactation_id');
        $std = \App\Support\Feed::standardFor($animal, \App\Support\Feed::latestWeights()[$animal->id] ?? null);

        return [
            'avg7' => \App\Support\Milking::weekAverages()[$animal->id] ?? null, 'high' => \App\Support\Milking::isHighYield($animal),
            'standard' => $std?->name, 'cp' => $std?->range(),
            'lactations' => $lactations->map(fn ($l) => ['number' => $l->number, 'started' => $l->started_on->format('j M Y'), 'dried' => $l->dried_on?->format('j M Y'),
                'days' => $l->daysInMilk(), 'total' => round((float) ($totals[$l->id] ?? 0), 1), 'reason' => $l->dry_reason, 'open' => $l->dried_on === null])->values(),
        ];
    }
}
