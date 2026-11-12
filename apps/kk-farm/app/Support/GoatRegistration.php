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
    /** D-059: the date in the Tag ID: purchase/arrival date for a bought goat, birth date for a farm-born one, else today. */
    public static function tagDate(array $data): Carbon
    {
        $date = ($data['origin'] ?? null) === 'born_on_farm' ? ($data['birth_date'] ?? null) : ($data['acquired_on'] ?? null);

        return $date ? Carbon::parse($date) : Feed::now();
    }

    /** D-065: height is entered in inches and kept in centimetres. */
    private static function heightCm(array $data): ?float
    {
        return isset($data['height_in']) && $data['height_in'] !== '' ? round((float) $data['height_in'] * 2.54, 1) : null;
    }

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
            // D-053: a bought or transferred-in goat often has no known birth date, so it is required only for farm-born goats.
            $presence = $d->key === 'birth_date' ? ['nullable', 'required_if:origin,born_on_farm'] : [$required ? 'required' : 'nullable'];
            $rules[$field] = [...$presence, ...self::typeRules($d)];
        }

        // D-093: a pregnant goat (pregnant when bought, or Female Status Pregnant) must have her temperature taken.
        if (isset($rules['temperature_f'])) {
            $rules['temperature_f'] = ['nullable', Rule::requiredIf(fn () => request('pregnant_at_arrival') === 'yes' || request('repro_status') === 'pregnant'), ...array_slice($rules['temperature_f'], 1)];
        }
        if (isset($rules['pregnancy_days'])) {
            $rules['pregnancy_days'] = ['nullable', 'required_if:pregnant_at_arrival,yes', ...array_slice($rules['pregnancy_days'], 1)];
        }
        if (isset($rules['days_in_milk'])) { // D-117
            $rules['days_in_milk'] = ['nullable', 'required_if:milking_at_arrival,yes', ...array_slice($rules['days_in_milk'], 1)];
        }

        // D-235: the Gender field picks a role code (BB, BD, FM …); gender, purpose and category follow from it.
        $rules['role_code'] = ['nullable', Rule::in(array_keys(Designations::PICK))];

        return $rules;
    }

    /** D-235: a picked role code sets gender, purpose and goat category (the code wins over the separate fields). */
    public static function applyRole(array $data): array
    {
        if (! empty($data['role_code']) && ($p = Designations::PICK[$data['role_code']] ?? null)) {
            $data['sex'] = $p[0];
            $data['animal_purpose'] = $p[1] ?? ($data['animal_purpose'] ?? null);
            $data['animal_class'] = $p[2];
            $data['registered_role'] = $data['role_code'];
        }
        unset($data['role_code']);

        return $data;
    }

    /** D-093: goat body temperature in °F. Normal 101.3–103.5 (38.5–39.7 °C). */
    public const TEMP_NORMAL_F = [101.3, 103.5];

    private static function typeRules(FieldDefinition $d): array
    {
        if ($d->builtin) {
            $special = match ($d->key) {
                'rfid_epc' => ['string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
                'tag_id' => ['string', 'max:40', Rule::unique('animals', 'farm_number')],
                'breed_id' => ['integer', 'exists:breeds,id'],
                'pen_id' => ['integer', Rule::exists('pens', 'id')->where('kind', 'pen')], // D-087: a home pen, never an open area
                'sire_id' => ['uuid', 'exists:animals,id'],
                'dam_id' => ['uuid', 'exists:animals,id'],
                'birth_date', 'acquired_on' => ['date', 'before_or_equal:today'],
                'birth_weight_kg' => ['numeric', 'between:0.1,12'],
                'latest_weight_kg' => ['numeric', 'between:0.5,150'],
                'height_in' => ['numeric', 'between:4,80'],
                'temperature_f' => ['numeric', 'between:93,110'], // D-093: °F
                'pregnancy_days' => ['integer', 'between:1,160'], // D-098: days pregnant
                'days_in_milk' => ['integer', 'between:0,400'], // D-117: days since she kidded
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
        $data = self::applyRole($data);
        // D-237: a bought goat with a gender code and no age gets the estimated age the code means (BK → kid …)
        if (empty($data['birth_date']) && ! empty($data['registered_role']) && ($data['origin'] ?? null) !== 'born_on_farm'
            && ($st = AgeStages::stages()->firstWhere('code', Designations::stageFor($data['registered_role'])))) {
            $data['birth_date'] = now()->subDays((int) round($st->estimateMonths() * 30.4375))->toDateString();
        }
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

        // D-047: age and category left empty are not saved: they are worked out from the birth date whenever they
        // are shown, so a kid becomes a grower and an adult by itself. Breed purity does not change, so it is saved.
        [$data['breed_purity'], $data['cross_breeds']] = self::purity($data);

        return DB::transaction(function () use ($data, $user, $epc, $via, $mating) {
            $lifecycle = $data['lifecycle_status'] ?? 'active';
            [$status, $exitReason] = self::LIFECYCLE[$lifecycle] ?? ['active', null];
            $repro = $data['repro_status'] ?? null;
            if (! $repro && ($data['sex'] ?? null) === 'female') { // D-236: the starting female status from Automation Rules
                $repro = AutoRules::for('female', AgeStages::codeFor($data['birth_date'] ?? null), $data['animal_purpose'] ?? null)?->female_status;
            }

            $animal = Animal::create([
                // D-041: a blank Tag ID is generated from the breed (BT-0001 ...). A typed one is kept as entered.
                'farm_number' => trim((string) ($data['tag_id'] ?? '')) ?: Breed::findOrFail($data['breed_id'])->issueTagId(self::tagDate($data), $data['origin'] ?? null),
                'name' => $data['name'] ?? null,
                'sex' => $data['sex'],
                'breed_id' => $data['breed_id'],
                'pen_id' => $data['pen_id'],
                'birth_date' => $data['birth_date'] ?? null,
                // D-065: a bought or transferred goat's birth date is always an estimate; a farm-born one is exact.
                'birth_date_is_estimate' => ($data['origin'] ?? null) !== 'born_on_farm' && ! empty($data['birth_date']),
                'origin' => $data['origin'],
                'sire_id' => $data['sire_id'] ?? null,
                'dam_id' => $data['dam_id'] ?? null,
                'sire_tag' => $data['sire_tag'] ?? null,
                'dam_tag' => $data['dam_tag'] ?? null,
                'acquired_on' => $data['acquired_on'] ?? null,
                'source_farm' => $data['source_farm'] ?? null,
                'animal_class' => $data['animal_class'] ?? null,
                'registered_role' => $data['registered_role'] ?? null,
                'age_category' => empty($data['birth_date']) ? ($data['age_category'] ?? null) : null, // D-067: automatic from the birth date
                'animal_purpose' => $data['animal_purpose'] ?? null,
                'kid_stage' => $data['kid_stage'] ?? null,
                'breed_purity' => $data['breed_purity'] ?? null,
                'cross_breeds' => $data['cross_breeds'] ?? null,
                'repro_status' => $repro,
                'repro_state' => Classification::LEGACY_REPRO[$repro] ?? null,
                'health_flag' => $data['health_flag'] ?? null,
                'health_status' => self::HEALTH[$data['health_flag'] ?? 'healthy'] ?? 'healthy',
                'ownership' => $data['ownership'] ?? null,
                'birth_weight_kg' => $data['birth_weight_kg'] ?? null,
                'teeth' => $data['teeth'] ?? null, // D-091
                'height_cm' => self::heightCm($data),
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
                WeightRecord::create(['animal_id' => $animal->id, 'measured_on' => now(), 'weight_kg' => $data['latest_weight_kg'], 'height_cm' => self::heightCm($data), 'body_score' => isset($data['body_score']) ? (int) $data['body_score'] : null, 'context' => 'registration', 'measured_by' => $user->name]);
            }
            self::arrivalCheck($animal, $data, $user);
            self::pregnantOnArrival($animal, $data, $user);
            // D-117: a doe bought in milk starts her lactation from about when she kidded.
            if (($data['milking_at_arrival'] ?? null) === 'yes' && $animal->sex === 'female' && $animal->origin !== 'born_on_farm') {
                Milking::start($animal->fresh(), now()->subDays((int) ($data['days_in_milk'] ?? 0))->startOfDay(), 'arrival', $user->name);
                if (in_array($data['repro_status'] ?? null, ['pregnant', 'expected_kidding'], true) || ($data['pregnant_at_arrival'] ?? null) === 'yes') {
                    $animal->update(['repro_status' => 'pregnant', 'repro_state' => 'pregnant']); // pregnant and still milking
                }
            }

            $origin = ['purchased' => 'Purchased and registered', 'transferred_in' => 'Transferred in and registered'][$data['origin']] ?? 'Born on the farm';
            Farm::event($animal, 'origin', $origin.($via === 'scan' ? ' (started from an RFID scan)' : ''), $user->name);

            if ($epc !== '' && $status === 'active') {
                Farm::assignTag($animal, RfidTag::firstOrCreate(['tag_code' => $epc], ['status' => 'in_stock']), 'initial', $user);
            }

            // D-172: a purchased or transferred goat starts in intake quarantine until the vet clears her.
            if ($status === 'active' && in_array($animal->origin, ['purchased', 'transferred_in'], true)) {
                Intake::open($animal->fresh(), $user);
            }

            Farm::log($user, "Registered goat {$animal->farm_number}", "/goats/{$animal->id}");

            return $animal;
        });
    }

    /** D-091: temperature and body condition taken at registration, kept as an observation in the health history. */
    private static function arrivalCheck(Animal $animal, array $data, User $user): void
    {
        $temp = isset($data['temperature_f']) && $data['temperature_f'] !== '' ? (float) $data['temperature_f'] : null;
        $score = $data['body_score'] ?? null;
        if ($temp === null && ! $score) {
            return;
        }
        [$low, $high] = self::TEMP_NORMAL_F;
        $fever = $temp !== null && $temp > $high;
        $cold = $temp !== null && $temp < $low;
        $notes = collect([$temp !== null ? "Temperature {$temp} °F".($fever ? " (fever: above {$high} °F)" : ($cold ? " (low: below {$low} °F)" : ' (normal)')) : null, $score ? "Body condition {$score} of 5" : null])->filter()->implode('. ').'.';
        \App\Models\HealthRecord::create([
            'animal_id' => $animal->id, 'kind' => 'observation', 'occurred_on' => now()->toDateString(), 'title' => $fever ? 'Arrival check: fever' : ($cold ? 'Arrival check: low temperature' : 'Arrival check'),
            'notes' => $notes, 'severity' => $fever || $cold ? 'high' : null, 'recorded_by' => $user->name, 'recorded_role' => $user->roleLabel(), 'status' => $fever || $cold ? 'open' : 'closed',
        ]);
        // D-093: outside normal, the vets get a task today (the open report also shows in the Action Center).
        if ($fever || $cold) {
            \App\Models\Task::create([
                'title' => mb_substr("Examine {$animal->farm_number}: ".($fever ? 'fever' : 'low temperature')." {$temp} °F on arrival", 0, 190), 'type' => 'health', 'priority' => 'critical', 'status' => 'open',
                'due_on' => now()->toDateString(), 'animal_id' => $animal->id, 'pen_id' => $animal->pen_id,
                'department_id' => \App\Models\Department::where('name', 'Veterinary')->value('id'), 'notes' => mb_substr("Recorded at registration by {$user->name}. Normal {$low}–{$high} °F.", 0, 500),
            ]);
        }
    }

    /**
     * D-091: a doe bought or transferred in pregnant goes straight into Breeding: a pregnant mating with the sire unknown,
     * dated back from the months pregnant, with the farm's gestation for the expected kidding. The vet confirms by check.
     */
    private static function pregnantOnArrival(Animal $animal, array $data, User $user): void
    {
        if (($data['pregnant_at_arrival'] ?? null) !== 'yes' || $animal->sex !== 'female' || $animal->origin === 'born_on_farm') {
            return;
        }
        $org = \App\Support\Tenant::org();
        $gestation = (int) ($org->gestation_days ?? 150);
        $days = isset($data['pregnancy_days']) && $data['pregnancy_days'] !== '' ? (int) $data['pregnancy_days'] : 75;
        $mated = now()->subDays($days)->startOfDay();
        Mating::create([
            'dam_id' => $animal->id, 'sire_id' => null, 'mated_on' => $mated, 'method' => 'natural', 'status' => 'pregnant',
            'expected_kidding_on' => $mated->copy()->addDays($gestation), 'recorded_by' => $user->name,
            'notes' => "Pregnant when she came to the farm; sire unknown. Mating date estimated from about {$days} days pregnant.",
        ]);
        $animal->update(['repro_status' => 'pregnant', 'repro_state' => Classification::LEGACY_REPRO['pregnant'] ?? 'pregnant']);
        Farm::event($animal, 'breeding', 'Pregnant on arrival. Expected kidding about '.$mated->copy()->addDays($gestation)->format('j M Y'), $user->name);
    }

    /** Fields a person may change after registering (D-091). Measurements (weight, temperature) are new records instead. */
    public const EDITABLE = [
        'origin', 'breed_id', 'acquired_on', 'tag_id', 'sex', 'name', 'source_farm', 'purchase_cost_pkr', 'teeth', 'birth_date', 'birth_weight_kg',
        'sire_id', 'dam_id', 'breed_purity', 'cross_breeds', 'age_category', 'animal_purpose', 'animal_class', 'registered_role', 'repro_status', 'kid_stage',
        'health_flag', 'pen_id', 'ownership', 'notes', 'lifecycle_status',
    ];

    /**
     * D-091: change a goat's details. Each change is kept in the goat's history as "Field: old → new" with who and when,
     * so nothing is lost. A pen change goes through a recorded transfer.
     *
     * @return list<string> the changes made
     */
    public static function update(Animal $animal, array $data, User $user): array
    {
        $data = self::applyRole($data);
        $labels = FieldCatalog::forAnimal(true)->pluck('label', 'key');
        $map = [
            'tag_id' => 'farm_number', 'purchase_cost_pkr' => 'purchase_cost_minor',
        ];
        $changes = [];
        $updates = [];
        foreach (self::EDITABLE as $key) {
            if (! array_key_exists($key, $data) || in_array($key, ['pen_id', 'lifecycle_status'], true)) {
                continue;
            }
            $column = $map[$key] ?? $key;
            $new = $data[$key] === '' ? null : $data[$key];
            if ($key === 'tag_id') {
                $new = $new ? strtoupper(trim((string) $new)) : $animal->farm_number; // the Tag ID can change, never be emptied
            }
            if ($key === 'purchase_cost_pkr') {
                $new = $new === null ? null : (int) round($new * 100);
            }
            $old = $animal->getAttribute($column);
            $oldText = $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : ($old === null ? '' : (string) $old);
            $newText = $new === null ? '' : (string) $new;
            if (in_array($key, ['birth_weight_kg'], true) && $oldText !== '' && $newText !== '' && (float) $oldText === (float) $newText) {
                continue;
            }
            if ($oldText === $newText) {
                continue;
            }
            $updates[$column] = $new;
            $show = fn ($v) => $v === '' ? 'empty' : ($key === 'purchase_cost_pkr' ? 'PKR '.number_format((int) $v / 100) : ($key === 'breed_id' ? (\App\Models\Breed::find($v)?->name ?? $v) : (in_array($key, ['sire_id', 'dam_id'], true) ? (Animal::find($v)?->farm_number ?? $v) : $v)));
            $changes[] = ($labels[$key] ?? $key).': '.$show($oldText).' → '.$show($newText);
        }
        if (isset($updates['sex']) && $updates['sex'] !== 'female') {
            $updates['repro_status'] = null;
        }
        if (array_key_exists('repro_status', $updates)) {
            $updates['repro_state'] = Classification::LEGACY_REPRO[$updates['repro_status']] ?? null;
        }
        if (array_key_exists('health_flag', $updates)) {
            $updates['health_status'] = self::HEALTH[$updates['health_flag'] ?? 'healthy'] ?? 'healthy';
        }
        if (array_key_exists('birth_date', $updates)) {
            $updates['birth_date_is_estimate'] = ($updates['origin'] ?? $animal->origin) !== 'born_on_farm' && ! empty($updates['birth_date']);
        }
        if (isset($updates['farm_number']) && Animal::where('farm_number', $updates['farm_number'])->whereKeyNot($animal->id)->exists()) {
            throw ValidationException::withMessages(['tag_id' => "{$updates['farm_number']} is already another goat's Tag ID."]);
        }
        foreach (['sire_id', 'dam_id'] as $parent) {
            if (! empty($updates[$parent]) && $updates[$parent] === $animal->id) {
                throw ValidationException::withMessages([$parent => 'A goat cannot be its own parent.']);
            }
        }

        return DB::transaction(function () use ($animal, $data, $user, $updates, $changes) {
            if ($updates) {
                $animal->update($updates);
            }
            if (! empty($data['pen_id']) && (int) $data['pen_id'] !== (int) $animal->pen_id) {
                \App\Support\Movements::transfer($animal->fresh('pen.shed'), \App\Models\Pen::with('shed')->findOrFail($data['pen_id']), $user, ['reason' => 'Corrected on the goat record', 'care' => true]);
                $changes[] = 'Pen moved (see Movements)';
            }
            if ($changes) {
                Farm::event($animal, 'edit', 'Edited: '.implode('; ', $changes), $user->name);
                Farm::log($user, "Edited goat {$animal->fresh()->farm_number}", "/goats/{$animal->id}");
            }

            return $changes;
        });
    }

    /**
     * Breed purity from the parents' breeds when both are registered: same breed is Purebred, different breeds
     * a first cross ("Beetal × Teddy"). Whatever the user chose is kept.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function purity(array $data): array
    {
        $purity = $data['breed_purity'] ?? null;
        $cross = $data['cross_breeds'] ?? null;
        if (empty($data['sire_id']) || empty($data['dam_id'])) {
            return [$purity, $cross];
        }
        $breeds = Animal::with('breed')->whereIn('id', [$data['sire_id'], $data['dam_id']])->get()->keyBy('id');
        $sire = $breeds[$data['sire_id']]->breed ?? null;
        $dam = $breeds[$data['dam_id']]->breed ?? null;
        if (! $sire || ! $dam) {
            return [$purity, $cross];
        }
        if ($sire->id === $dam->id) {
            return [$purity ?: 'pure', $cross];
        }

        return [$purity ?: 'first_cross', $cross ?: "{$sire->name} × {$dam->name}"];
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

    /** Goat categories that only fit one sex (D-046). Cull fits both; categories a farm adds are not restricted. */
    public static function classSex(): array
    {
        return Classification::categorySex();
    }

    private static function assertParents(array $data): void
    {
        $needs = self::classSex()[$data['animal_class'] ?? ''] ?? null;
        if ($needs && $needs !== ($data['sex'] ?? null)) {
            $label = Classification::CATEGORIES[$data['animal_class']][0];
            throw ValidationException::withMessages(['animal_class' => "$label is only for ".($needs === 'female' ? 'female' : 'male').' goats.']);
        }
        if (! empty($data['repro_status']) && ($data['sex'] ?? null) === 'male') {
            throw ValidationException::withMessages(['repro_status' => 'Female Status is only recorded for female goats.']);
        }

        if (! empty($data['sire_id']) && Animal::whereKey($data['sire_id'])->value('sex') !== 'male') {
            throw ValidationException::withMessages(['sire_id' => 'The sire must be a male goat.']);
        }
        if (! empty($data['dam_id']) && Animal::whereKey($data['dam_id'])->value('sex') !== 'female') {
            throw ValidationException::withMessages(['dam_id' => 'The dam must be a female goat.']);
        }
    }
}
