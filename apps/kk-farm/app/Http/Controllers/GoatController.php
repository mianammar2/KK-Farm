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
        return Inertia::render('Goats/Index', [
            'goats' => Animal::with(['breed', 'pen', 'latestWeight', 'photos', 'openAssignment.tag'])->orderBy('farm_number')->get()
                ->map(fn (Animal $a) => $a->card() + ['tag' => $a->openAssignment?->tag?->tag_code]),
            'breeds' => Breed::orderBy('name')->pluck('name', 'id'),
            'pens' => Pen::orderBy('name')->get(['id', 'name', 'purpose']),
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
                'breed_id' => Breed::orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['value' => $b->id, 'label' => $b->name]),
                'pen_id' => Pen::with('shed')->orderBy('name')->get()->map(fn (Pen $p) => ['value' => $p->id, 'label' => trim(($p->shed?->name ?? '').' / '.$p->name.($p->purpose ? " · {$p->purpose}" : ''), ' /')]),
                'sire_id' => Animal::where('sex', 'male')->orderBy('farm_number')->get(['id', 'farm_number', 'name'])->map(fn ($a) => ['value' => $a->id, 'label' => $a->farm_number.($a->name ? " · {$a->name}" : '')]),
                'dam_id' => Animal::where('sex', 'female')->orderBy('farm_number')->get(['id', 'farm_number', 'name'])->map(fn ($a) => ['value' => $a->id, 'label' => $a->farm_number.($a->name ? " · {$a->name}" : '')]),
            ],
            'canAssignTags' => $request->user()->passes('assign_tags'),
            'untagged' => Animal::active()->whereDoesntHave('openAssignment')->orderBy('farm_number')->get(['id', 'farm_number', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
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
                'origin' => $animal->origin, 'birthDate' => $animal->birth_date?->format('j M Y'), 'estimated' => $animal->birth_date_is_estimate,
                'shed' => $animal->pen?->shed?->name, 'penPurpose' => $animal->pen?->purpose, 'feedingGroup' => $animal->feedingGroup?->name,
                'exitReason' => $animal->exit_reason, 'exitDate' => $animal->exit_date?->format('j M Y'), 'adg' => Farm::adg($weights),
                'lifetimeAdg' => $weightDays ? (int) round(($weights->last()->weight_kg - $weights->first()->weight_kg) * 1000 / $weightDays) : null,
                'identity' => $animal->id,
            ],
            'photos' => $animal->photos->map(fn (AnimalPhoto $p) => ['id' => $p->id, 'notes' => $p->notes, 'isProfile' => $p->is_profile, 'by' => $p->added_by, 'on' => $p->created_at->format('j M Y')]),
            'tags' => RfidAssignment::with('tag')->where('animal_id', $animal->id)->orderByDesc('valid_from')->get()
                ->map(fn (RfidAssignment $r) => ['code' => $r->tag->tag_code, 'from' => $r->valid_from->format('j M Y'), 'to' => $r->valid_to?->format('j M Y'), 'reason' => $r->reason_assigned, 'closed' => $r->reason_unassigned, 'by' => $r->assigned_by]),
            'weights' => $weights->map(fn ($w) => ['on' => $w->measured_on->format('Y-m-d'), 'kg' => $w->weight_kg]),
            'timeline' => $animal->events()->limit(60)->get()->map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'summary' => $e->summary, 'by' => $e->recorded_by, 'on' => $e->occurred_at->format('j M Y')]),
            'health' => $animal->healthRecords()->limit(40)->get()->map(fn ($h) => ['id' => $h->id, 'kind' => $h->kind, 'title' => $h->title, 'notes' => $h->notes, 'severity' => $h->severity, 'by' => $h->recorded_by, 'role' => $h->recorded_role, 'on' => $h->occurred_on->format('j M Y'), 'next' => $h->next_due_on?->format('j M Y'), 'status' => $h->status]),
            'pedigree' => Farm::pedigree($animal),
            'offspring' => $offspring->map(fn (Animal $a) => $a->card()),
            'matings' => Mating::with(['sire', 'dam'])->where($animal->sex === 'male' ? 'sire_id' : 'dam_id', $animal->id)->orderByDesc('mated_on')->limit(12)->get()
                ->map(fn (Mating $m) => ['id' => $m->id, 'on' => $m->mated_on->format('j M Y'), 'partner' => ($animal->sex === 'male' ? $m->dam : $m->sire)?->farm_number, 'status' => $m->status, 'expected' => $m->expected_kidding_on?->format('j M Y')]),
            'kiddings' => Kidding::where('dam_id', $animal->id)->orderByDesc('kidded_on')->get()->map(fn (Kidding $k) => ['id' => $k->id, 'on' => $k->kidded_on->format('j M Y'), 'alive' => $k->born_alive, 'stillborn' => $k->stillborn]),
            'cost' => $canFinance ? $this->illustrativeCost($animal) : null,
            'pens' => Pen::orderBy('name')->get(['id', 'name', 'purpose']),
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

    public function exitFarm(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('exit_animal'), 403);
        $data = $request->validate(['reason' => ['required', Rule::in(['sold', 'gifted', 'sacrificed', 'died'])]]);
        abort_unless($animal->status === 'active', 422);

        DB::transaction(function () use ($animal, $data, $request): void {
            Farm::closeOpenTag($animal, $data['reason'], $request->user());
            $animal->update([
                'status' => $data['reason'] === 'died' ? 'deceased' : 'left_farm', 'exit_reason' => $data['reason'],
                'exit_date' => now(), 'pen_id' => null, 'feeding_group_id' => null,
            ]);
            Farm::event($animal, 'exit', "Left the farm: {$data['reason']}", $request->user()->name);
        });
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
                fputcsv($out, [$a->farm_number, $a->name, $a->sex, $a->breed?->name, $a->birth_date?->toDateString(), $a->pen?->name, $a->status, $a->health_status, $a->latestWeight?->weight_kg]);
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
