<?php

namespace App\Support;

use App\Models\Breed;
use App\Models\FieldDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The KK Farms "Goat Register" sheet as registration fields (D-040): same fields, same order, same dropdown options.
 * Every organization gets these when it is created and can then relabel them, edit dropdown options and add its own.
 */
final class FieldCatalog
{
    /** Fields the registration cannot work without. They cannot be switched off or made optional. */
    public const STRUCTURAL = ['sex', 'breed_id', 'pen_id', 'origin', 'birth_date'];

    /** Pakistani breeds with the KK Farms ERP labels (D-046). Farms add more in Settings. */
    public const BREEDS = ['Beetal', 'Makhi Cheeni (Beetal Variety)', 'Teddy', 'Nachi', 'Dera Din Panah (DDP)', 'Kamori', 'Gulabi', 'Barbari', 'Crossbreed', 'Other'];

    /** Dropdown option lists: the workbook's data validation, and the KK Farms classification (D-046). */
    public static function options(): array
    {
        return [
            'sex' => ['female' => 'Female', 'male' => 'Male'],
            'origin' => ['born_on_farm' => 'Farm Born', 'purchased' => 'Purchased', 'transferred_in' => 'Transferred In'],
            'animal_class' => Classification::categoryOptions(),
            'repro_status' => Classification::FEMALE_STATUSES,
            'age_category' => Classification::AGE_CATEGORIES,
            'animal_purpose' => Classification::PURPOSES,
            'kid_stage' => Classification::KID_STAGES,
            'breed_purity' => Classification::PURITY,
            'lifecycle_status' => ['active' => 'Active', 'sold' => 'Sold', 'dead' => 'Dead', 'culled' => 'Culled', 'transferred' => 'Transferred'],
            'health_flag' => ['healthy' => 'Healthy', 'monitor' => 'Monitor', 'sick' => 'Sick', 'quarantine' => 'Quarantine', 'recovering' => 'Recovering'],
            'ownership' => ['kk_farms' => 'KK Farms', 'partner' => 'Partner', 'consignment' => 'Consignment', 'other' => 'Other'],
        ];
    }

    /** @return list<array{key: string, label: string, type: string, section: string, required?: bool, help?: string}> */
    public static function defaults(): array
    {
        return [
            ['key' => 'rfid_epc', 'label' => 'RFID EPC', 'type' => 'scan', 'section' => 'Identity', 'help' => 'Filled by the scanner. Scan a tag, or type the code.'],
            ['key' => 'breed_id', 'label' => 'Breed', 'type' => 'lookup', 'section' => 'Identity', 'required' => true],
            ['key' => 'tag_id', 'label' => 'Tag ID', 'type' => 'text', 'section' => 'Identity', 'help' => 'Set automatically from the breed when you save (for example BT-0001). Type a number only to use a different one.'],
            ['key' => 'name', 'label' => 'Animal Name', 'type' => 'text', 'section' => 'Identity'],
            ['key' => 'sex', 'label' => 'Gender', 'type' => 'select', 'section' => 'Identity', 'required' => true],
            ['key' => 'birth_date', 'label' => 'Birth Date', 'type' => 'date', 'section' => 'Birth and origin', 'required' => true],
            ['key' => 'birth_date_is_estimate', 'label' => 'Birth date is an estimate', 'type' => 'yes_no', 'section' => 'Birth and origin'],
            ['key' => 'birth_weight_kg', 'label' => 'Birth Weight Kg', 'type' => 'number', 'section' => 'Birth and origin'],
            ['key' => 'origin', 'label' => 'Origin Type', 'type' => 'select', 'section' => 'Birth and origin', 'required' => true],
            ['key' => 'acquired_on', 'label' => 'Acquired Date', 'type' => 'date', 'section' => 'Birth and origin'],
            ['key' => 'source_farm', 'label' => 'Source Farm', 'type' => 'text', 'section' => 'Birth and origin'],
            ['key' => 'sire_id', 'label' => 'Sire Tag', 'type' => 'lookup', 'section' => 'Parentage'],
            ['key' => 'dam_id', 'label' => 'Dam Tag', 'type' => 'lookup', 'section' => 'Parentage'],
            ['key' => 'breed_purity', 'label' => 'Breed Purity', 'type' => 'select', 'section' => 'Parentage', 'help' => 'Set automatically when both parents are known.'],
            ['key' => 'cross_breeds', 'label' => 'Cross Details', 'type' => 'text', 'section' => 'Parentage', 'help' => 'For a crossbreed, for example Beetal × Teddy.'],
            ['key' => 'age_category', 'label' => 'Age Category', 'type' => 'select', 'section' => 'Classification', 'help' => 'Worked out from the birth date if left empty.'],
            ['key' => 'animal_purpose', 'label' => 'Animal Purpose', 'type' => 'select', 'section' => 'Classification'],
            ['key' => 'animal_class', 'label' => 'Goat Category', 'type' => 'select', 'section' => 'Classification', 'help' => 'Suggested from gender, age and purpose if left empty.'],
            ['key' => 'repro_status', 'label' => 'Female Status', 'type' => 'select', 'section' => 'Classification', 'help' => 'Reproductive status. Female goats only.'],
            ['key' => 'kid_stage', 'label' => 'Kid Stage', 'type' => 'select', 'section' => 'Classification'],
            ['key' => 'lifecycle_status', 'label' => 'Lifecycle Status', 'type' => 'select', 'section' => 'Status'],
            ['key' => 'health_flag', 'label' => 'Health Flag', 'type' => 'select', 'section' => 'Status'],
            ['key' => 'pen_id', 'label' => 'Shed / Pen', 'type' => 'lookup', 'section' => 'Housing and weight', 'required' => true],
            ['key' => 'latest_weight_kg', 'label' => 'Latest Weight Kg', 'type' => 'number', 'section' => 'Housing and weight', 'help' => 'Saved as the first weighing in the goat\'s weight log.'],
            ['key' => 'purchase_cost_pkr', 'label' => 'Purchase Cost PKR', 'type' => 'number', 'section' => 'Ownership and cost'],
            ['key' => 'ownership', 'label' => 'Ownership', 'type' => 'select', 'section' => 'Ownership and cost'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'long_text', 'section' => 'Notes'],
        ];
    }

    /** Creates the standard registration fields for an organization (explicit org id, so no tenant needs to be bound). */
    public static function seed(string $orgId): void
    {
        foreach (self::defaults() as $i => $d) {
            $options = self::options()[$d['key']] ?? null;
            DB::table('field_definitions')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'org_id' => $orgId, 'entity' => 'animal', 'key' => $d['key'], 'label' => $d['label'], 'type' => $d['type'],
                'options' => $options ? json_encode(collect($options)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()) : null,
                'section' => $d['section'], 'sort' => ($i + 1) * 10, 'required' => $d['required'] ?? false, 'builtin' => true, 'active' => true,
                'help' => $d['help'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // lifecycle_status is deliberately absent from OPTIONS' required flag: new goats default to Active.
    }

    /** The workbook's breeds for an organization that has none yet. */
    public static function seedBreeds(): void
    {
        foreach (self::BREEDS as $name) {
            Breed::firstOrCreate(['name' => $name], ['default_gestation_days' => config('kkfarm.demo_gestation_days')]);
        }
    }

    /** Active fields for the current organization, in form order. */
    public static function forAnimal(bool $includeInactive = false)
    {
        return FieldDefinition::where('entity', 'animal')
            ->when(! $includeInactive, fn ($q) => $q->where('active', true))
            ->orderBy('sort')->orderBy('label')->get();
    }

    /** A machine key for a custom field label, unique within the organization. */
    public static function keyFor(string $label): string
    {
        $base = Str::slug($label, '_') ?: 'field';
        $key = $base;
        for ($n = 2; FieldDefinition::where('entity', 'animal')->where('key', $key)->exists(); $n++) {
            $key = "{$base}_$n";
        }

        return $key;
    }
}
