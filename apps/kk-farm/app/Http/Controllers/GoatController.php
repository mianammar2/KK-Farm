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
            'roleNames' => collect(\App\Support\Designations::allCodes())->keys()->mapWithKeys(fn ($c) => [$c => \App\Support\Designations::labels([$c])[$c] ?? $c]), // D-252: the Role column
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

        return Inertia::render('Goats/Register', ['epc' => $epc !== '' ? $epc : null, 'registered' => $this->registered()] + $this->formProps($request));
    }

    /**
     * D-280: every goat in the register with how it came (purchased, transferred in, born on the farm), when it
     * arrived and when it was entered, and by whom; the register page shows it as a spreadsheet under the form.
     */
    private function registered(): array
    {
        $by = \App\Models\AnimalEvent::where('type', 'origin')->orderBy('occurred_at')->get(['animal_id', 'recorded_by'])->keyBy('animal_id');
        $origin = ['purchased' => 'Purchased', 'transferred_in' => 'Transferred in', 'born_on_farm' => 'Born on the farm'];

        // D-282: only the goats this panel registers (bought or transferred in); farm-born kids come from the kidding record
        return Animal::with(['breed', 'pen'])->whereNull('voided_at')->whereIn('origin', ['purchased', 'transferred_in'])->orderByDesc('created_at')->get()->map(fn (Animal $a) => [
            'id' => $a->id, 'number' => $a->farm_number, 'origin' => $origin[$a->origin] ?? ucfirst((string) $a->origin),
            'breed' => $a->breed?->name, 'sex' => $a->sex === 'female' ? 'Female' : ($a->sex === 'male' ? 'Male' : null), 'code' => $a->classCode(),
            'role' => \App\Support\Designations::main($a), 'pen' => $a->pen?->name, 'arrived' => ($a->origin === 'born_on_farm' ? $a->birth_date : $a->acquired_on)?->format('d/m/Y'),
            'source' => $a->source_farm, 'enteredIso' => $a->created_at?->setTimezone('Asia/Karachi')->format('Y-m-d'),
            'entered' => $a->created_at?->setTimezone('Asia/Karachi')->format('d/m/Y g:i A'), 'by' => $by[$a->id]->recorded_by ?? null,
            'status' => $a->status === 'active' ? 'On farm' : 'Left',
        ])->values()->all();
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
            Farm::event($animal, 'health', 'Castrated ('.self::CASTRATION_METHODS[$data['method']].'): now a wether, breeding blocked. Recovery check due '.$on->copy()->addDays(7)->format('d/m').'.', $request->user()->name, $on);
        });
        Farm::log($request->user(), "Castration recorded for {$animal->farm_number}", "/goats/{$animal->id}");

        return back()->with('success', "{$animal->farm_number} recorded as castrated: now a wether (WTH) and cannot be used as a sire.");
    }

    public const CASTRATION_METHODS = ['band' => 'Rubber ring / band', 'burdizzo' => 'Burdizzo (bloodless)', 'surgical' => 'Surgical'];

    public function teeth(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('edit_goats') || $request->user()->passes('health_checkup') || $request->user()->passes('check_teeth'), 403); // D-252/D-262: a teeth check is an examination
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
                'breed_id' => (fn ($used) => Breed::orderBy('name')->get()->map(fn (Breed $b) => ['value' => $b->id, 'label' => $b->name, 'next' => $b->nextTagId(), 'code' => $b->code, 'used' => (object) ($used[$b->code] ?? [])]))(Breed::usedNumbers()), // D-291: the number restarts at 01 for each breed, date and origin
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
                    'matedOn' => $m->mated_on->format('d/m/Y'), 'expected' => $m->expected_kidding_on?->format('Y-m-d'), 'expectedLabel' => $m->expected_kidding_on?->format('d/m/Y'),
                ]]),
            'mixedBreedId' => Breed::whereIn('name', ['Crossbreed', 'Mixed'])->value('id'),
            'canAssignTags' => $request->user()->passes('assign_tags'),
            'untagged' => Animal::active()->whereDoesntHave('openAssignment')->orderBy('farm_number')->get(['id', 'farm_number', 'name']),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(\App\Support\Pages::allows($request->user(), '/goats/register'), 403, 'Your role cannot register goats.'); // D-241 (RBAC §8.3)
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

    /**
     * D-249: every measurement and care of one goat, one row per day, newest first: weight (with the change and daily gain
     * since the weighing before), body score, height, temperature, heart and breathing rate, teeth, ultrasound, care given,
     * treatments and the checkup's result. Health columns only for the roles that see health (RBAC §7).
     */
    private function records(Animal $animal, $weights, bool $seeHealth): array
    {
        $days = [];
        $day = function &(string $on) use (&$days) {
            $days[$on] ??= ['on' => $on, 'weight' => null, 'change' => null, 'pct' => null, 'days' => null, 'adg' => null, 'bcs' => null, 'height' => null, 'temp' => null, 'tempFlag' => false,
                'heart' => null, 'breaths' => null, 'teeth' => null, 'ultrasound' => null, 'care' => [], 'treatments' => [], 'actions' => [], 'result' => null, 'by' => []];

            return $days[$on];
        };
        $prev = null;
        foreach ($weights as $w) {
            $on = $w->measured_on->format('Y-m-d');
            $r = &$day($on);
            $r['weight'] = (float) $w->weight_kg;
            $r['bcs'] = $w->body_score ?? $r['bcs'];
            $r['height'] = $w->height_cm !== null ? round((float) $w->height_cm / 2.54, 1) : $r['height'];
            if ($prev) {
                $r['change'] = round((float) $w->weight_kg - (float) $prev->weight_kg, 1);
                $gap = max(1, (int) $prev->measured_on->diffInDays($w->measured_on));
                $r['adg'] = (int) round($r['change'] * 1000 / $gap);
                $r['days'] = $gap; // D-254: over how many days
                $r['pct'] = (float) $prev->weight_kg > 0 ? round(($w->weight_kg - $prev->weight_kg) / $prev->weight_kg * 100, 1) : null;
            }
            $r['by'][] = $w->measured_by;
            unset($r);
            $prev = $w;
        }
        foreach (\App\Models\HeightRecord::where('animal_id', $animal->id)->orderBy('measured_on')->orderBy('id')->get() as $h) { // D-262: a height on its own
            $r = &$day($h->measured_on->format('Y-m-d'));
            $r['height'] = round((float) $h->height_cm / 2.54, 1);
            $r['by'][] = $h->measured_by;
            unset($r);
        }
        // D-262: what was done for the goat: moves with their reason, and being taken off Watch
        foreach (\App\Models\AnimalMovement::with('toPen')->where('animal_id', $animal->id)->where('type', 'transfer')->get() as $m) {
            $r = &$day($m->moved_on->format('Y-m-d'));
            $r['actions'][] = 'Moved'.($m->toPen ? " to {$m->toPen->name}" : '').($m->reason ? ": {$m->reason}" : '');
            unset($r);
        }
        foreach ($animal->dentalExams()->get() as $e) {
            $r = &$day($e->examined_on->format('Y-m-d'));
            $r['teeth'] = trim(($e->dental_code ?? '').($e->condition && $e->condition !== 'normal' ? " ({$e->condition})" : '')) ?: null;
            unset($r);
        }
        if ($seeHealth) {
            foreach (\App\Models\HealthCheckup::where('animal_id', $animal->id)->get() as $c) {
                $r = &$day($c->checked_on->format('Y-m-d'));
                $r['temp'] = $c->temperature_f; $r['tempFlag'] = (bool) $c->flags();
                $r['heart'] = $c->heart_rate; $r['breaths'] = $c->respiration_rate;
                $r['bcs'] = $r['bcs'] ?? $c->body_score;
                $r['weight'] = $r['weight'] ?? ($c->weight_kg ? (float) $c->weight_kg : null);
                $r['ultrasound'] = $c->ultrasound ? str_replace('_', ' ', $c->ultrasound).($c->days_pregnant ? ", day {$c->days_pregnant}" : '').($c->fetuses ? ", {$c->fetuses} ".($c->fetuses > 1 ? 'kids' : 'kid') : '') : null;
                $r['result'] = \App\Models\HealthCheckup::OUTCOMES[$c->outcome] ?? $c->outcome;
                $r['by'][] = $c->examiner;
                unset($r);
            }
            foreach ($animal->healthRecords()->get() as $h) {
                $r = &$day($h->occurred_on->format('Y-m-d'));
                if ($h->kind === 'observation') { // D-262: a finding; everything else (vaccine, deworming …) is care given
                    $r['care'][] = preg_replace('/^Checkup: /', '', $h->title);
                } else {
                    $r['actions'][] = ucfirst(str_replace('_', ' ', $h->kind)).': '.$h->title;
                }
                $r['by'][] = $h->recorded_by;
                unset($r);
            }
            foreach (\App\Models\Treatment::where('animal_id', $animal->id)->get() as $t) {
                $r = &$day($t->given_on->format('Y-m-d'));
                $r['actions'][] = trim("Treated: {$t->product} {$t->dose}".($t->route ? " ({$t->route})" : ''));
                $r['by'][] = $t->recorded_by;
                unset($r);
            }
        }
        // D-262: taken off Watch: shown on the day of the measurement that cleared it (the latest day on record up to then)
        foreach (\App\Models\AnimalEvent::where('animal_id', $animal->id)->where('summary', 'like', 'Out of Watch:%')->get() as $ev) {
            $on = $ev->occurred_at->format('Y-m-d');
            $at = collect(array_keys($days))->filter(fn ($d) => $d <= $on)->sort()->last() ?? $on;
            $r = &$day($at);
            $r['actions'][] = 'Taken off Watch ('.trim(substr($ev->summary, 13)).')';
            unset($r);
        }
        krsort($days);

        return array_values(array_map(fn ($r) => ['by' => array_values(array_unique(array_filter($r['by'])))] + $r, $days));
    }

    /**
     * D-260: the death report (pending, approved or returned) and, once the goat has died, its review: time, cause,
     * who reported and approved it, and everything medical in the 60 days before (cases, treatments and medicines,
     * checkups and temperatures, care given, weights).
     */
    private function death(Animal $animal, bool $seeHealth): ?array
    {
        $r = \App\Models\DeathReport::where('animal_id', $animal->id)->latest('id')->first();
        if (! $r && $animal->exit_reason !== 'died') {
            return null;
        }
        $out = $r ? ['id' => $r->id, 'status' => $r->status, 'diedAt' => $r->died_at->format('d/m/Y H:i'), 'cause' => $r->cause, 'circumstances' => $seeHealth ? $r->circumstances : null,
            'postmortem' => $seeHealth ? $r->postmortem : null, 'reportedBy' => $r->reported_by, 'decidedBy' => $r->decided_by, 'decidedAt' => $r->decided_at?->format('d/m/Y'), 'note' => $r->decision_note] : null;
        if ($animal->exit_reason !== 'died' || ! $seeHealth) {
            return $out ? ['report' => $out, 'review' => null] : null;
        }
        $end = $r?->died_at ?? $animal->exit_date ?? now();
        $from = $end->copy()->subDays(60)->toDateString();
        $cases = \App\Models\HealthCase::with('treatments')->where('animal_id', $animal->id)->where(fn ($q) => $q->whereDate('onset_on', '>=', $from)->orWhereIn('status', \App\Models\HealthCase::ACTIVE))->orderBy('onset_on')->get();
        $treatments = \App\Models\Treatment::where('animal_id', $animal->id)->whereDate('given_on', '>=', $from)->orderBy('given_on')->get();
        $checks = \App\Models\HealthCheckup::where('animal_id', $animal->id)->whereDate('checked_on', '>=', $from)->orderBy('checked_on')->get();
        $weights = \App\Models\WeightRecord::where('animal_id', $animal->id)->whereDate('measured_on', '>=', $from)->orderBy('measured_on')->get();
        $care = \App\Models\HealthRecord::where('animal_id', $animal->id)->whereDate('occurred_on', '>=', $from)->whereNotIn('kind', ['postmortem'])->orderBy('occurred_on')->get();

        return ['report' => $out, 'review' => [
            'from' => \Illuminate\Support\Carbon::parse($from)->format('d/m/Y'),
            'cases' => $cases->map(fn ($c) => ['label' => $c->label(), 'condition' => $c->condition, 'onset' => $c->onset_on->format('d/m'), 'status' => \App\Models\HealthCase::STATUSES[$c->status] ?? $c->status,
                'severity' => $c->severity, 'vet' => $c->vet, 'diagnosis' => $c->diagnosis])->values(),
            'treatments' => $treatments->map(fn ($t) => ['on' => $t->given_on->format('d/m'), 'product' => $t->product, 'dose' => $t->dose, 'route' => $t->route, 'by' => $t->recorded_by])->values(),
            'checkups' => $checks->map(fn ($c) => ['on' => $c->checked_on->format('d/m'), 'temp' => $c->temperature_f, 'result' => \App\Models\HealthCheckup::OUTCOMES[$c->outcome] ?? $c->outcome, 'by' => $c->examiner, 'findings' => $c->findings])->values(),
            'care' => $care->map(fn ($h) => ['on' => $h->occurred_on->format('d/m'), 'what' => ucfirst(str_replace('_', ' ', $h->kind)).': '.$h->title, 'by' => $h->recorded_by])->values(),
            'weights' => $weights->map(fn ($w) => ['on' => $w->measured_on->format('d/m'), 'kg' => (float) $w->weight_kg])->values(),
        ]];
    }

    /** D-248: a goat's card for the 3D shed (tap a goat): the essentials, health only for the roles that see it (RBAC §7). */
    public function peek(Request $request, Animal $animal): \Illuminate\Http\JsonResponse
    {
        $animal->load(['breed', 'pen.shed', 'latestWeight', 'photos']);
        $u = $request->user();
        $seeHealth = $u->passes('see_health_history'); // D-266 F-15: a right the Access Rights screen controls
        $w = $animal->latestWeight;

        return response()->json($animal->card() + [
            'weighedOn' => $w?->measured_on?->format('d/m/Y'),
            'bodyScore' => $w?->body_score,
            'pregnancyDay' => \App\Support\Checkups::dayOfPregnancy($animal, now()->toDateString()),
            'withdrawal' => $seeHealth ? \App\Support\Withdrawal::summary($animal) : null,
            'openCases' => $seeHealth ? \App\Models\HealthCase::where('animal_id', $animal->id)->whereNotIn('status', ['recovered', 'closed', 'deceased'])->pluck('condition')->values() : [],
            'seeHealth' => $seeHealth,
        ]);
    }

    public function show(Request $request, Animal $animal): Response
    {
        $animal->load(['breed', 'pen.shed', 'feedingGroup', 'latestWeight', 'photos', 'sire.breed', 'dam.breed', 'sire.sire', 'sire.dam', 'dam.sire', 'dam.dam']);
        $weights = $animal->weights()->get();
        $offspring = Animal::with(['breed', 'pen', 'latestWeight', 'photos'])->where($animal->sex === 'male' ? 'sire_id' : 'dam_id', $animal->id)->orderBy('birth_date')->get();
        $canFinance = $request->user()->canModule('finance');
        // D-241 (RBAC §7): health history, diagnoses and treatments for the vet, owner and manager; others see the status flag only
        $seeHealth = $request->user()->passes('see_health_history'); // D-266 F-15
        $pedigree = $request->user()->passes('see_goat_pedigree'); // D-266 F-14: the feeding staff see tag, pen and feeding group, not parentage or records
        $weightDays = $weights->count() > 1 ? max(1, $weights->first()->measured_on->diffInDays($weights->last()->measured_on)) : null;

        return Inertia::render('Goats/Show', [
            'goat' => $animal->card() + [
                'origin' => $animal->origin, 'birthDate' => $animal->birth_date?->format('d/m/Y'), 'estimated' => $animal->birth_date_is_estimate, 'height' => $animal->height_cm !== null ? round((float) $animal->height_cm / 2.54, 1) : null, // inches (D-065)
                'shed' => $animal->pen?->shed?->name, 'penPurpose' => $animal->pen?->purpose, 'feedingGroup' => $animal->feedingGroup?->name ?? FeedingGroup::where('code', \App\Support\Feed::groupCode($animal))->value('name'), // D-054: automatic group
                'withdrawal' => \App\Support\Withdrawal::summary($animal), // D-073
                // D-267: when it came to the farm: the purchase or transfer date (the registration date when none was entered), and from where
                'arrivedOn' => ($animal->acquired_on ?? ($animal->origin !== 'born_on_farm' ? $animal->created_at : null))?->format('d/m/Y'), 'arrivedEntered' => (bool) $animal->acquired_on, 'sourceFarm' => $animal->source_farm,
                'saleHint' => $animal->status === 'active' ? \App\Support\Movements::saleHint($animal) : null, // D-290: Sick and the disease from an open case
                'inBreeding' => in_array($animal->effectiveCategory(), ['breeding_male', 'breeding_female', 'replacement_male', 'replacement_female'], true), // D-260: can be culled
                'exitReason' => $animal->exit_reason, 'exitDate' => $animal->exit_date?->format('d/m/Y'), 'adg' => Farm::adg($weights),
                'lifetimeAdg' => $weightDays ? (int) round(($weights->last()->weight_kg - $weights->first()->weight_kg) * 1000 / $weightDays) : null,
                'identity' => $animal->id,
                'removal' => $animal->removal_requested_at || $animal->status === 'voided' ? ['by' => $animal->removal_requested_by, 'on' => $animal->removal_requested_at?->format('d/m/Y g:i A'), 'reason' => $animal->removal_reason, 'voidedBy' => $animal->voided_by, 'voidedOn' => $animal->voided_at?->format('d/m/Y g:i A')] : null, // D-091
                'teeth' => $animal->teeth ? (\App\Support\Teeth::options()[$animal->teeth] ?? $animal->teeth) : null,
                'ageStage' => ($st = \App\Support\AgeStages::forBirth($animal->birth_date, $animal->exit_date)) ? ['code' => $st->code, 'label' => $st->label, 'range' => $st->rangeText()] : null, // D-229
                'dental' => ['code' => \App\Support\Teeth::code($animal->teethCode()), 'fromAge' => ! $animal->teeth && $animal->teethCode() !== null, 'condition' => \App\Support\Teeth::CONDITIONS[$animal->dental_condition] ?? null, 'checkedOn' => $animal->teeth_checked_on?->format('d/m/Y'),
                    'history' => $animal->dentalExams()->limit(10)->get()->map(fn ($e) => ['on' => $e->examined_on->format('d/m/Y'), 'code' => \App\Support\Teeth::code($e->dental_code), 'condition' => \App\Support\Teeth::CONDITIONS[$e->condition] ?? null, 'by' => $e->recorded_by, 'source' => $e->source])->all()],
                'classification' => $animal->classification(),
                'sire' => ! $pedigree ? null : ($animal->sire ? ['id' => $animal->sire->id, 'number' => $animal->sire->farm_number] : ($animal->sire_tag ? ['id' => null, 'number' => $animal->sire_tag] : null)),
                'dam' => ! $pedigree ? null : ($animal->dam ? ['id' => $animal->dam->id, 'number' => $animal->dam->farm_number] : ($animal->dam_tag ? ['id' => null, 'number' => $animal->dam_tag] : null)),
            ],
            'photos' => $animal->photos->map(fn (AnimalPhoto $p) => ['id' => $p->id, 'notes' => $p->notes, 'isProfile' => $p->is_profile, 'by' => $p->added_by, 'on' => $p->created_at->format('d/m/Y')]),
            'tags' => RfidAssignment::with('tag')->where('animal_id', $animal->id)->orderByDesc('valid_from')->get()
                ->map(fn (RfidAssignment $r) => ['code' => $r->tag->tag_code, 'from' => $r->valid_from->format('d/m/Y'), 'to' => $r->valid_to?->format('d/m/Y'), 'reason' => $r->reason_assigned, 'closed' => $r->reason_unassigned, 'by' => $r->assigned_by]),
            'weights' => $weights->map(fn ($w) => ['on' => $w->measured_on->format('Y-m-d'), 'kg' => $w->weight_kg]),
            'records' => ! $pedigree ? [] : $this->records($animal, $weights, $seeHealth), // D-249: the goat's measurements and care, one row per day
            'saleReasons' => \App\Support\Movements::SALE_REASONS, 'diseases' => \App\Support\Movements::diseaseOptions(), // D-290
            'death' => $this->death($animal, $seeHealth), // D-260: the vet's death report, and the review once it is approved
            // D-251: how it is doing since a watch began, or when it last came off Watch
            'recovery' => $seeHealth || $request->user()->passes('see_recovery_progress') ? ((($p = \App\Support\Recovery::progress($animal)) ? ($seeHealth ? $p : ['title' => 'Health watch', 'temp' => null] + $p) : null) ?? (($e = \App\Models\AnimalEvent::where('animal_id', $animal->id)->where('summary', 'like', 'Out of Watch:%')->where('occurred_at', '>=', now()->subDays(30))->latest('occurred_at')->first())
                ? ['state' => 'recovered', 'on' => $e->occurred_at->format('d/m'), 'why' => trim(substr($e->summary, strlen('Out of Watch:')))] : null)) : null,
            'timeline' => $animal->events()->limit(60)->get()->map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'summary' => $e->summary, 'by' => $e->recorded_by, 'on' => $e->occurred_at->format('d/m/Y')]),
            // D-073: health cases with their treatment doses
            'cases' => ! $seeHealth ? [] : \App\Models\HealthCase::with('treatments')->where('animal_id', $animal->id)->orderByDesc('onset_on')->get()->map(fn ($c) => [
                'id' => $c->id, 'label' => $c->label(), 'condition' => $c->condition, 'status' => \App\Models\HealthCase::STATUSES[$c->status], 'active' => in_array($c->status, \App\Models\HealthCase::ACTIVE, true),
                'severity' => \App\Models\HealthCase::SEVERITIES[$c->severity], 'onset' => $c->onset_on->format('d/m/Y'), 'resolved' => $c->resolved_on?->format('d/m/Y'), 'diagnosis' => $c->diagnosis,
                'treatments' => $c->treatments->map(fn ($t) => ['id' => $t->id, 'on' => $t->given_on->format('d/m'), 'text' => "{$t->product} {$t->dose}", 'meat' => $t->meat_clear_on?->format('d/m/Y'), 'milk' => $t->milk_clear_on?->format('d/m/Y'), 'by' => $t->recorded_by])->values(),
            ]),
            'health' => ! $seeHealth ? [] : $animal->healthRecords()->limit(40)->get()->map(fn ($h) => ['id' => $h->id, 'kind' => $h->kind, 'title' => $h->title, 'notes' => $h->notes, 'severity' => $h->severity, 'by' => $h->recorded_by, 'role' => $h->recorded_role, 'on' => $h->occurred_on->format('d/m/Y'), 'next' => $h->next_due_on?->format('d/m/Y'), 'status' => $h->status]),
            'pedigree' => Farm::pedigree($animal),
            'offspring' => ! $pedigree ? [] : $offspring->map(fn (Animal $a) => $a->card()),
            'matings' => Mating::with(['sire', 'dam'])->where($animal->sex === 'male' ? 'sire_id' : 'dam_id', $animal->id)->orderByDesc('mated_on')->limit(12)->get()
                ->map(fn (Mating $m) => ['id' => $m->id, 'on' => $m->mated_on->format('d/m/Y'), 'partner' => ($animal->sex === 'male' ? $m->dam : $m->sire)?->farm_number, 'status' => $m->status, 'expected' => $m->expected_kidding_on?->format('d/m/Y')]),
            'kiddings' => Kidding::where('dam_id', $animal->id)->orderByDesc('kidded_on')->get()->map(fn (Kidding $k) => ['id' => $k->id, 'on' => $k->kidded_on->format('d/m/Y'), 'alive' => $k->born_alive, 'stillborn' => $k->stillborn]),
            'cost' => $canFinance ? $this->illustrativeCost($animal) : null,
            'castration' => $animal->sex === 'male' ? ['on' => $animal->castrated_on?->format('d/m/Y'), 'methods' => self::CASTRATION_METHODS, 'can' => $request->user()->passes('clinical')] : null, // D-230
            'statuses' => $animal->status === 'active' ? (function () use ($animal) { \App\Support\Lifecycle::record($animal); return \App\Support\Lifecycle::statuses($animal); })() : null, // D-232
            'statusHistory' => \App\Models\StatusHistory::where('animal_id', $animal->id)->whereNotNull('from_status')->latest('changed_at')->latest('id')->limit(20)->get()
                ->map(fn ($h) => ['dimension' => $h->dimension, 'from' => \App\Support\Lifecycle::LABELS[$h->dimension][$h->from_status] ?? $h->from_status, 'to' => \App\Support\Lifecycle::LABELS[$h->dimension][$h->to_status] ?? $h->to_status, 'rule' => $h->rule, 'at' => $h->changed_at->format('d/m/Y g:i A')]),
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
        abort_unless($request->user()->passes('edit_goats'), 403); // D-252: a correction of the goat's details
        $data = $request->validate([
            'birth_date' => ['nullable', 'required_without:age_months', 'date', 'before_or_equal:today'], 'age_months' => ['nullable', 'integer', 'between:0,240'],
            'estimated' => ['boolean'], 'reason' => ['required', 'string', 'max:200'],
        ], [], ['age_months' => 'age in months']);
        $new = ! empty($data['birth_date']) ? \Illuminate\Support\Carbon::parse($data['birth_date']) : now()->subMonths((int) $data['age_months']);
        $old = $animal->birth_date?->format('d/m/Y') ?? 'none';
        $animal->update(['birth_date' => $new->toDateString(), 'birth_date_is_estimate' => (bool) ($data['estimated'] ?? empty($data['birth_date'])), 'age_category' => null]);
        Farm::event($animal, 'origin', "Birth date corrected from {$old} to {$new->format('d/m/Y')}: {$data['reason']}", $request->user()->name);
        Farm::log($request->user(), "Corrected the birth date of {$animal->farm_number}", "/goats/{$animal->id}");

        return back()->with('success', "Birth date of {$animal->farm_number} is now {$new->format('d/m/Y')}.");
    }

    /**
     * D-270: everything recorded for this goat on one day, opened from its Measurements and care table: weighings,
     * heights, checkups, findings and care, treatments, teeth, moves. Health only for the roles that see it.
     */
    public function day(Request $request, Animal $animal, string $on): \Illuminate\Http\JsonResponse
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $on), 404);
        $u = $request->user();
        abort_unless($u->passes('see_goat_pedigree'), 403); // D-266 F-14: the day-by-day records are not for the feeding staff
        $seeHealth = $u->passes('see_health_history'); // D-266 F-15: a right the Access Rights screen controls (the day details were added with the old role list)
        $time = fn ($m) => $m->created_at?->setTimezone('Asia/Karachi')->format('g:i A');
        $out = [
            'weights' => \App\Models\WeightRecord::where('animal_id', $animal->id)->whereDate('measured_on', $on)->get()->map(fn ($w) => ['kg' => (float) $w->weight_kg, 'bcs' => $w->body_score,
                'height' => $w->height_cm !== null ? round($w->height_cm / 2.54, 1) : null, 'context' => $w->context, 'by' => $w->measured_by, 'at' => $time($w)])->values(),
            'heights' => \App\Models\HeightRecord::where('animal_id', $animal->id)->whereDate('measured_on', $on)->get()->map(fn ($h) => ['in' => round($h->height_cm / 2.54, 1), 'by' => $h->measured_by, 'at' => $time($h)])->values(),
            'moves' => \App\Models\AnimalMovement::with(['fromPen', 'toPen'])->where('animal_id', $animal->id)->whereDate('moved_on', $on)->get()->map(fn ($m) => ['type' => \App\Models\AnimalMovement::TYPES[$m->type][0] ?? $m->type,
                'from' => $m->fromPen?->name, 'to' => $m->toPen?->name, 'reason' => $m->reason, 'by' => $m->authorized_by, 'at' => $time($m)])->values(),
            'teeth' => $animal->dentalExams()->whereDate('examined_on', $on)->get()->map(fn ($e) => ['code' => $e->dental_code, 'condition' => $e->condition, 'by' => $e->examined_by ?? null])->values(),
            'checkups' => [], 'care' => [], 'treatments' => [],
        ];
        if ($seeHealth) {
            $out['checkups'] = \App\Models\HealthCheckup::where('animal_id', $animal->id)->whereDate('checked_on', $on)->get()->map(fn ($c) => ['temp' => $c->temperature_f, 'fever' => (bool) $c->flags(),
                'heart' => $c->heart_rate, 'breaths' => $c->respiration_rate, 'result' => \App\Models\HealthCheckup::OUTCOMES[$c->outcome] ?? $c->outcome, 'findings' => $c->findings, 'by' => $c->examiner, 'at' => $time($c)])->values();
            $out['care'] = \App\Models\HealthRecord::where('animal_id', $animal->id)->whereDate('occurred_on', $on)->get()->map(fn ($h) => ['kind' => ucfirst(str_replace('_', ' ', $h->kind)),
                'title' => preg_replace('/^Checkup: /', '', $h->title), 'notes' => $h->notes, 'status' => $h->status, 'by' => $h->recorded_by, 'at' => $time($h)])->values();
            $out['treatments'] = \App\Models\Treatment::where('animal_id', $animal->id)->whereDate('given_on', $on)->get()->map(fn ($t) => ['product' => $t->product, 'dose' => $t->dose, 'route' => $t->route,
                'meat' => $t->meat_clear_on?->format('d/m/Y'), 'milk' => $t->milk_clear_on?->format('d/m/Y'), 'by' => $t->recorded_by, 'at' => $time($t)])->values();
        }

        return response()->json($out);
    }

    /** D-262: a height on its own, with its date (the vet's or the administrator's); the goat's current height follows the latest. */
    public function height(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('record_height'), 403);
        abort_unless($animal->status === 'active', 422);
        $data = $request->validate(['height_in' => ['required', 'numeric', 'between:4,60'], 'measured_on' => ['required', 'date', 'before_or_equal:today']], [], ['height_in' => 'height']);
        $cm = round((float) $data['height_in'] * 2.54, 1);
        \App\Models\HeightRecord::create(['animal_id' => $animal->id, 'measured_on' => $data['measured_on'], 'height_cm' => $cm, 'measured_by' => $request->user()->name]);
        $latest = \App\Models\HeightRecord::where('animal_id', $animal->id)->orderByDesc('measured_on')->orderByDesc('id')->first();
        $animal->update(['height_cm' => $latest->height_cm]);
        Farm::event($animal, 'weight', "Height {$data['height_in']} in measured", $request->user()->name, \Illuminate\Support\Carbon::parse($data['measured_on']));

        return back()->with('success', "Height {$data['height_in']} in saved for {$animal->farm_number}.");
    }

    /** The goat page's "Record exit": one movement through the movement service (D-076). */
    public function exitFarm(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('exit_animal'), 403);
        $data = $request->validate([
            // D-260: a death goes through the vet's report and the administrator's approval; culling takes a goat out of breeding, not off the farm
            'reason' => ['required', Rule::in(array_diff(array_keys(\App\Support\Movements::REASON_TO_TYPE), ['died', 'culled']))],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'], 'destination' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:500'],
        ] + \App\Support\Movements::saleReasonRules('reason,sold'), ['reason.in' => 'A death is reported by the vet and approved by the administrator; culling is done from the breeding lists.',
            'sale_reason.required_if' => 'Choose why the goat was sold.', 'disease_id.required_if' => 'Choose the disease.', 'sale_reason_note.required_if' => 'Write the reason.']);
        abort_unless($animal->status === 'active', 422);
        $type = \App\Support\Movements::REASON_TO_TYPE[$data['reason']];
        if ($type === 'sale') {
            $data['reason'] = \App\Support\Movements::saleReasonText($data) ?? 'sold'; // D-290: the reason line says why
        }
        try {
            \App\Support\Movements::exit($animal, $type, $request->user(), $data);
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
            fputcsv($out, ['Number', 'Name', 'Sex', 'Breed', 'Born', 'Classification code', 'Age stage', 'Teeth', 'Dental condition', 'Role codes', 'PEN', 'Status', 'Health', 'Latest weight (KG)']); // D-227: age stage code
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
            'lactations' => $lactations->map(fn ($l) => ['number' => $l->number, 'started' => $l->started_on->format('d/m/Y'), 'dried' => $l->dried_on?->format('d/m/Y'),
                'days' => $l->daysInMilk(), 'total' => round((float) ($totals[$l->id] ?? 0), 1), 'reason' => $l->dry_reason, 'open' => $l->dried_on === null])->values(),
        ];
    }
}
