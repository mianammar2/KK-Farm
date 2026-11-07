<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\AnimalPhoto;
use App\Models\Kidding;
use App\Models\Mating;
use App\Models\Breed;
use App\Models\FieldDefinition;
use App\Models\RfidAssignment;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\WeightRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Registers a goat from the Goat Register form (D-040): validation comes from the organization's field definitions,
 * and the same code serves a scan, a manual entry and the quick form on the herd list.
 */
final class GoatRegistration
{
    /** Workbook health flag to the app's health states, until health state is derived (D-038). */
    private const HEALTH = ['healthy' => 'healthy', 'monitor' => 'watch', 'sick' => 'treatment', 'quarantine' => 'treatment', 'recovering' => 'watch'];

    /** Workbook lifecycle status to the app's exit model. */
    private const LIFECYCLE = ['sold' => ['left_farm', 'sold'], 'dead' => ['deceased', 'died'], 'culled' => ['left_farm', 'culled'], 'transferred' => ['left_farm', 'transferred']];

    public static function normaliseCode(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    /** @param Collection<int, FieldDefinition> $definitions */
    public static function rules(Collection $definitions): array
    {
        $rules = [
            'custom_fields' => ['nullable', 'array'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:15360'],
            // D-043: parents typed by ear tag when they are not (yet) registered.
            'sire_tag' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'dam_tag' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\/-]+$/'],
        ];

        foreach ($definitions as $d) {
            $required = $d->required || in_array($d->key, FieldCatalog::STRUCTURAL, true);
            $field = $d->builtin ? $d->key : "custom_fields.{$d->key}";
            $rules[$field] = [$required ? 'required' : 'nullable', ...self::typeRules($d)];
        }

        return $rules;
    }

    private static function typeRules(FieldDefinition $d): array
    {
        if ($d->builtin) {
            $special = match ($d->key) {
                'rfid_epc' => ['string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
                'tag_id' => ['string', 'max:40', Rule::unique('animals', 'farm_number')],
                'breed_id' => ['integer', 'exists:breeds,id'],
                'pen_id' => ['integer', 'exists:pens,id'],
                'sire_id' => ['uuid', 'exists:animals,id'],
                'dam_id' => ['uuid', 'exists:animals,id'],
                'birth_date', 'acquired_on' => ['date', 'before_or_equal:today'],
                'birth_weight_kg' => ['numeric', 'between:0.1,12'],
                'latest_weight_kg' => ['numeric', 'between:0.5,150'],
                'purchase_cost_pkr' => ['numeric', 'min:0', 'max:100000000'],
                'name', 'source_farm' => ['string', 'max:60'],
                default => null,
            };
            if ($special) {
                return $special;
            }
        }

        return match ($d->type) {
            'number' => ['numeric', 'between:-1000000000,1000000000'],
            'date' => ['date'],
            'yes_no' => ['boolean'],
            'select' => ['string', Rule::in(array_keys($d->optionMap()))],
            'long_text' => ['string', 'max:2000'],
            default => ['string', 'max:200'],
        };
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public static function register(array $data, User $user, string $via = 'manual'): Animal
    {
        $epc = self::normaliseCode($data['rfid_epc'] ?? null);

        // D-043: a typed parent tag that belongs to a registered goat becomes a real link; otherwise it is kept as text.
        [$data['sire_id'], $data['sire_tag']] = self::resolveParent($data['sire_id'] ?? null, $data['sire_tag'] ?? null);
        [$data['dam_id'], $data['dam_tag']] = self::resolveParent($data['dam_id'] ?? null, $data['dam_tag'] ?? null);
        $ownTag = strtoupper(trim((string) ($data['tag_id'] ?? '')));
        if ($ownTag !== '' && in_array($ownTag, [$data['sire_tag'], $data['dam_tag']], true)) {
            throw ValidationException::withMessages([$data['sire_tag'] === $ownTag ? 'sire_id' : 'dam_id' => 'A goat cannot be its own parent.']);
        }

        // D-042: a farm-born kid with a known dam inherits the sire of the dam's mating in the gestation window.
        $mating = self::birthMating($data);
        if ($mating && empty($data['sire_id']) && empty($data['sire_tag']) && $mating->sire_id) {
            $data['sire_id'] = $mating->sire_id;
        }

        if ($epc !== '') {
            if (! $user->passes('assign_tags')) {
                throw ValidationException::withMessages(['rfid_epc' => 'Your role cannot assign RFID tags. Register the goat without a tag, or ask a manager.']);
            }
            self::assertTagFree($epc);
        }
        self::assertParents($data);

        return DB::transaction(function () use ($data, $user, $epc, $via, $mating) {
            $lifecycle = $data['lifecycle_status'] ?? 'active';
            [$status, $exitReason] = self::LIFECYCLE[$lifecycle] ?? ['active', null];
            $repro = $data['repro_status'] ?? null;

            $animal = Animal::create([
                // D-041: a blank Tag ID is generated from the breed (BT-0001 ...). A typed one is kept as entered.
                'farm_number' => trim((string) ($data['tag_id'] ?? '')) ?: Breed::findOrFail($data['breed_id'])->issueTagId(),
                'name' => $data['name'] ?? null,
                'sex' => $data['sex'],
                'breed_id' => $data['breed_id'],
                'pen_id' => $data['pen_id'],
                'birth_date' => $data['birth_date'],
                'birth_date_is_estimate' => (bool) ($data['birth_date_is_estimate'] ?? false),
                'origin' => $data['origin'],
                'sire_id' => $data['sire_id'] ?? null,
                'dam_id' => $data['dam_id'] ?? null,
                'sire_tag' => $data['sire_tag'] ?? null,
                'dam_tag' => $data['dam_tag'] ?? null,
                'acquired_on' => $data['acquired_on'] ?? null,
                'source_farm' => $data['source_farm'] ?? null,
                'animal_class' => $data['animal_class'] ?? null,
                'repro_status' => $repro,
                'repro_state' => in_array($repro, ['pregnant', 'lactating'], true) ? $repro : null,
                'health_flag' => $data['health_flag'] ?? null,
                'health_status' => self::HEALTH[$data['health_flag'] ?? 'healthy'] ?? 'healthy',
                'ownership' => $data['ownership'] ?? null,
                'birth_weight_kg' => $data['birth_weight_kg'] ?? null,
                'purchase_cost_minor' => isset($data['purchase_cost_pkr']) ? (int) round($data['purchase_cost_pkr'] * 100) : null,
                'notes' => $data['notes'] ?? null,
                'custom_fields' => $data['custom_fields'] ?? [],
                'coat' => 'brown',
                'status' => $status,
                'exit_reason' => $exitReason,
                'exit_date' => $exitReason ? now() : null,
            ]);

            // Goats registered earlier with this goat's tag as their parent are now linked to it.
            self::linkWaitingChildren($animal, $user);

            // The kidding is recorded from the kids themselves: the first kid creates it, twins add to it.
            if ($mating && (! $mating->sire_id || $mating->sire_id === $animal->sire_id)) {
                self::recordBirth($animal, $mating, $user);
            }

            if (! empty($data['photo'])) {
                self::storeProfilePhoto($animal, $data['photo'], $user);
            }

            if (isset($data['latest_weight_kg'])) {
                WeightRecord::create(['animal_id' => $animal->id, 'measured_on' => now(), 'weight_kg' => $data['latest_weight_kg'], 'context' => 'registration', 'measured_by' => $user->name]);
            }

            $origin = ['purchased' => 'Purchased and registered', 'transferred_in' => 'Transferred in and registered'][$data['origin']] ?? 'Born on the farm';
            Farm::event($animal, 'origin', $origin.($via === 'scan' ? ' (started from an RFID scan)' : ''), $user->name);

            if ($epc !== '' && $status === 'active') {
                Farm::assignTag($animal, RfidTag::firstOrCreate(['tag_code' => $epc], ['status' => 'in_stock']), 'initial', $user);
            }

            Farm::log($user, "Registered goat {$animal->farm_number}", "/goats/{$animal->id}");

            return $animal;
        });
    }

    /** @return array{0: ?string, 1: ?string} [registered parent id, unregistered parent tag] */
    private static function resolveParent(?string $id, ?string $tag): array
    {
        if (! empty($id)) {
            return [$id, null];
        }
        $tag = strtoupper(trim((string) $tag));
        if ($tag === '') {
            return [null, null];
        }
        $registered = Animal::where('farm_number', $tag)->value('id');

        return $registered ? [$registered, null] : [null, $tag];
    }

    private static function linkWaitingChildren(Animal $parent, User $user): void
    {
        [$idColumn, $tagColumn, $role] = $parent->sex === 'male' ? ['sire_id', 'sire_tag', 'sire'] : ['dam_id', 'dam_tag', 'dam'];
        $children = Animal::where($tagColumn, $parent->farm_number)->whereNull($idColumn)->get();
        foreach ($children as $child) {
            $child->update([$idColumn => $parent->id, $tagColumn => null]);
            Farm::event($child, 'origin', "{$parent->farm_number} registered and linked as this goat's $role", $user->name);
        }
        if ($children->isNotEmpty()) {
            Farm::event($parent, 'origin', "Linked as $role of ".$children->pluck('farm_number')->implode(', ').' (entered earlier by tag)', $user->name);
        }
    }

    /** Days after mating in which a birth is attributed to it (gestation about 150 days, generous either side). */
    public const GESTATION_WINDOW = [120, 175];

    /** The dam's mating that this farm-born kid most likely came from, or null. */
    public static function birthMating(array $data): ?Mating
    {
        if (($data['origin'] ?? null) !== 'born_on_farm' || empty($data['dam_id']) || empty($data['birth_date'])) {
            return null;
        }

        return Mating::where('dam_id', $data['dam_id'])
            ->whereIn('status', ['open', 'pregnant', 'kidded'])
            ->whereRaw('(?::date - mated_on) BETWEEN ? AND ?', [Carbon::parse($data['birth_date'])->toDateString(), ...self::GESTATION_WINDOW])
            ->orderByRaw("CASE status WHEN 'pregnant' THEN 0 WHEN 'kidded' THEN 1 ELSE 2 END")
            ->orderByDesc('mated_on')
            ->first();
    }

    /** Creates the kidding for this mating, or adds this kid to it (twins, triplets), and links the kid to it. */
    private static function recordBirth(Animal $kid, Mating $mating, User $user): void
    {
        $kidding = Kidding::where('mating_id', $mating->id)->lockForUpdate()->first();
        if ($kidding) {
            $kidding->increment('born_alive');
        } else {
            $kidding = Kidding::create(['dam_id' => $mating->dam_id, 'mating_id' => $mating->id, 'kidded_on' => $kid->birth_date, 'born_alive' => 1, 'stillborn' => 0, 'recorded_by' => $user->name]);
        }
        $kid->update(['birth_kidding_id' => $kidding->id]);

        $mating->update(['status' => 'kidded']);
        $dam = Animal::find($mating->dam_id);
        $dam?->update(['repro_state' => 'lactating', 'repro_status' => 'lactating']);
        if ($dam) {
            Farm::event($dam, 'breeding', "Kid {$kid->farm_number} registered".($kidding->born_alive > 1 ? " ({$kidding->born_alive} kids in this kidding)" : ''), $user->name);
        }
    }

    private static function storeProfilePhoto(Animal $animal, UploadedFile $file, User $user): void
    {
        $path = $file->store("animals/{$animal->id}");
        abort_unless($path, 500, 'The photograph could not be stored.');
        AnimalPhoto::create(['animal_id' => $animal->id, 'path' => $path, 'notes' => 'Profile picture taken at registration', 'is_profile' => true, 'added_by' => $user->name]);
        Farm::event($animal, 'photo', 'Profile picture added at registration', $user->name);
    }

    private static function assertTagFree(string $epc): void
    {
        $tag = RfidTag::where('tag_code', $epc)->first();
        if (! $tag) {
            return;
        }
        if ($open = RfidAssignment::with('animal')->where('rfid_tag_id', $tag->id)->whereNull('valid_to')->first()) {
            throw ValidationException::withMessages(['rfid_epc' => "This tag is already on {$open->animal->farm_number}. Remove it there first."]);
        }
        if ($tag->status === 'lost') {
            throw ValidationException::withMessages(['rfid_epc' => 'This tag was reported lost and must be reviewed before it is used again.']);
        }
    }

    /** Animal classes that only fit one sex. Kid and Other fit both; classes a farm adds are not restricted. */
    public const CLASS_SEX = ['replacement_doe' => 'female', 'breeding_doe' => 'female', 'breeding_buck' => 'male', 'wether' => 'male'];

    private static function assertParents(array $data): void
    {
        $needs = self::CLASS_SEX[$data['animal_class'] ?? ''] ?? null;
        if ($needs && $needs !== ($data['sex'] ?? null)) {
            throw ValidationException::withMessages(['animal_class' => $needs === 'female'
                ? 'A doe class is only for female goats. For a male, choose Breeding Buck, Wether or Kid.'
                : 'Breeding Buck and Wether are only for male goats. For a female, choose Replacement Doe, Breeding Doe or Kid.']);
        }

        if (! empty($data['sire_id']) && Animal::whereKey($data['sire_id'])->value('sex') !== 'male') {
            throw ValidationException::withMessages(['sire_id' => 'The sire must be a male goat.']);
        }
        if (! empty($data['dam_id']) && Animal::whereKey($data['dam_id'])->value('sex') !== 'female') {
            throw ValidationException::withMessages(['dam_id' => 'The dam must be a female goat.']);
        }
    }
}
