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
            'showLabels' => \App\Support\HerdStats::LABELS,
            'breeds' => Breed::orderBy('name')->pluck('name', 'id'),
            'pens' => Pen::with('shed')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName(), 'purpose' => $p->purpose])->sortBy('name')->values(),
        ]);
    }

    /**
     * The Goat Register form (D-040). Opened from an RFID scan with the scanned code, or by hand.
     * The fields, their order and their dropdown options come from the organization's field definitions.
     */
    public function register(Request $request): Response
    {
        $epc = GoatRegistration::normaliseCode($request->query('epc'));

        return Inertia::render('Goats/Register', [
            'epc' => $epc !== '' ? $epc : null,
            'fields' => FieldCatalog::forAnimal()->map(fn (FieldDefinition $f) => [
                'key' => $f->key, 'label' => $f->label, 'type' => $f->type, 'section' => $f->section, 'help' => $f->help, 'builtin' => $f->builtin,
                'required' => $f->required || in_array($f->key, FieldCatalog::STRUCTURAL, true), 'options' => $f->type === 'select' ? $f->options : null,
            ])->values(),
            'lookups' => [
                'breed_id' => Breed::orderBy('name')->get()->map(fn (Breed $b) => ['value' => $b->id, 'label' => $b->name, 'next' => $b->nextTagId(), 'code' => $b->code, 'nextNumber' => $b->last_number + 1]),
                // Shed and pen are two linked dropdowns: choosing a shed lists only its pens. Planned sheds are not offered.
                'pen_id' => Pen::with('shed')->whereHas('shed', fn ($q) => $q->where('status', 'active'))->orderBy('name')->get()
                    ->map(fn (Pen $p) => ['value' => $p->id, 'label' => $p->fullName().($p->purpose ? " · {$p->purpose}" : ''), 'shed' => $p->shed_id, 'shedName' => trim($p->shed?->name.($p->shed?->typeName() ? ' · '.$p->shed->typeName() : ''))]),
                'sire_id' => Animal::where('sex', 'male')->orderBy('farm_number')->get(['id', 'farm_number', 'name', 'breed_id'])->map(fn ($a) => ['value' => $a->id, 'label' => $a->farm_number.($a->name ? " · {$a->name}" : ''), 'breed' => $a->breed_id, 'code' => $a->farm_number]),
                'dam_id' => Animal::where('sex', 'female')->orderBy('farm_number')->get(['id', 'farm_number', 'name', 'breed_id'])->map(fn ($a) => ['value' => $a->id, 'label' => $a->farm_number.($a->name ? " · {$a->name}" : ''), 'breed' => $a->breed_id, 'code' => $a->farm_number]),
            ],
            'classSex' => GoatRegistration::classSex(),
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Tag IDs are stored upper-case, the same as generated ones (BT-0001), so duplicates are caught either way.
        if ($request->filled('tag_id')) {
            $request->merge(['tag_id' => strtoupper(trim((string) $request->input('tag_id')))]);
        }
        $data = $request->validate(GoatRegistration::rules(FieldCatalog::forAnimal()));

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
            'pens' => Pen::with('shed')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName(), 'purpose' => $p->purpose])->sortBy('name')->values(),
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
            fputcsv($out, ['Synthetic demonstration data']);
            fputcsv($out, ['Number', 'Name', 'Sex', 'Breed', 'Born', 'Pen', 'Status', 'Health', 'Latest kg']);
            Animal::with(['breed', 'pen', 'latestWeight'])->orderBy('farm_number')->each(function (Animal $a) use ($out): void {
                fputcsv($out, [$a->farm_number, $a->name, $a->sex, $a->breed?->name, $a->birth_date?->toDateString(), $a->pen?->fullName(), $a->status, $a->health_status, $a->latestWeight?->weight_kg]);
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
}
