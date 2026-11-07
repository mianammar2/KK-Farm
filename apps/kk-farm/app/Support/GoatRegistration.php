<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\FieldDefinition;
use App\Models\RfidAssignment;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\WeightRecord;
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
        $rules = ['custom_fields' => ['nullable', 'array']];

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

        if ($epc !== '') {
            if (! $user->passes('assign_tags')) {
                throw ValidationException::withMessages(['rfid_epc' => 'Your role cannot assign RFID tags. Register the goat without a tag, or ask a manager.']);
            }
            self::assertTagFree($epc);
        }
        self::assertParents($data);

        return DB::transaction(function () use ($data, $user, $epc, $via) {
            $lifecycle = $data['lifecycle_status'] ?? 'active';
            [$status, $exitReason] = self::LIFECYCLE[$lifecycle] ?? ['active', null];
            $repro = $data['repro_status'] ?? null;

            $animal = Animal::create([
                'farm_number' => trim((string) ($data['tag_id'] ?? '')) ?: Farm::nextFarmNumber(),
                'name' => $data['name'] ?? null,
                'sex' => $data['sex'],
                'breed_id' => $data['breed_id'],
                'pen_id' => $data['pen_id'],
                'birth_date' => $data['birth_date'],
                'birth_date_is_estimate' => (bool) ($data['birth_date_is_estimate'] ?? false),
                'origin' => $data['origin'],
                'sire_id' => $data['sire_id'] ?? null,
                'dam_id' => $data['dam_id'] ?? null,
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

    private static function assertParents(array $data): void
    {
        if (! empty($data['sire_id']) && Animal::whereKey($data['sire_id'])->value('sex') !== 'male') {
            throw ValidationException::withMessages(['sire_id' => 'The sire must be a male goat.']);
        }
        if (! empty($data['dam_id']) && Animal::whereKey($data['dam_id'])->value('sex') !== 'female') {
            throw ValidationException::withMessages(['dam_id' => 'The dam must be a female goat.']);
        }
    }
}
