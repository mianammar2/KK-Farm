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
    public const STRUCTURAL = ['sex', 'breed_id', 'pen_id', 'origin', 'birth_date']; // birth_date is required only for farm-born goats (D-053)

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
            // D-091
            'teeth' => \App\Support\Classification::TEETH,
            'body_score' => ['1' => '1 · Very thin', '2' => '2 · Thin', '3' => '3 · Good', '4' => '4 · Fat', '5' => '5 · Very fat'],
            'pregnant_at_arrival' => ['yes' => 'Yes, pregnant', 'no' => 'Not pregnant', 'unknown' => 'Not known'],
            'milking_at_arrival' => ['yes' => 'Yes, in milk', 'no' => 'Not in milk'], // D-117
            'ownership' => ['kk_farms' => 'KK Farms', 'partner' => 'Partner', 'consignment' => 'Consignment', 'other' => 'Other'],
        ];
    }

    /** @return list<array{key: string, label: string, type: string, section: string, required?: bool, help?: string}> */
    public static function defaults(): array
    {
        return [
            // D-058: the register asks in the farm's order: origin first, then breed, the purchase date, and the Tag ID from the breed.
            ['key' => 'origin', 'label' => 'Origin Type', 'type' => 'select', 'section' => 'Origin and identity', 'required' => true],
            ['key' => 'breed_id', 'label' => 'Breed', 'type' => 'lookup', 'section' => 'Origin and identity', 'required' => true],
            ['key' => 'acquired_on', 'label' => 'Purchase Date', 'type' => 'date', 'section' => 'Origin and identity', 'help' => 'The day the goat arrived on the farm.'],
            ['key' => 'tag_id', 'label' => 'Tag ID', 'type' => 'text', 'section' => 'Origin and identity', 'help' => 'Set automatically: breed code, date (DDMMYY: purchase or birth date) with origin (P bought, T transferred, B born), and number, for example TD-141026P-03. Type one only to use a different Tag ID.'],
            ['key' => 'rfid_epc', 'label' => 'RFID EPC', 'type' => 'scan', 'section' => 'Origin and identity', 'help' => 'Filled by the scanner. Scan a tag, or type the code.'],
            ['key' => 'sex', 'label' => 'Gender', 'type' => 'select', 'section' => 'Origin and identity', 'required' => true],
            ['key' => 'source_farm', 'label' => 'Source Farm', 'type' => 'text', 'section' => 'Origin and identity'],
            ['key' => 'purchase_cost_pkr', 'label' => 'Purchase Cost PKR', 'type' => 'number', 'section' => 'Origin and identity'],
            ['key' => 'teeth', 'label' => 'Teeth (permanent incisors)', 'type' => 'select', 'section' => 'Birth', 'help' => 'Count the big front teeth on the lower jaw. Fills in the estimated age.'], // D-091
            ['key' => 'birth_date', 'label' => 'Birth Date', 'type' => 'date', 'section' => 'Birth', 'required' => true, 'help' => 'Required for farm-born goats. For a bought goat leave it empty if unknown.'],
            ['key' => 'birth_date_is_estimate', 'label' => 'Birth date is an estimate', 'type' => 'yes_no', 'section' => 'Birth'],
            ['key' => 'birth_weight_kg', 'label' => 'Birth Weight (KG)', 'type' => 'number', 'section' => 'Birth'],
            // D-063: weight and height follow the birth date (or the estimated age of a bought goat).
            ['key' => 'latest_weight_kg', 'label' => 'Weight (KG)', 'type' => 'number', 'section' => 'Birth', 'help' => 'Saved as the first weighing in the goat\'s weight log.'],
            ['key' => 'height_in', 'label' => 'Height (inches)', 'type' => 'number', 'section' => 'Birth', 'help' => 'At the shoulder (withers). Saved with the first weighing.'], // D-065: entered in inches, stored in cm
            ['key' => 'sire_id', 'label' => 'Sire Tag', 'type' => 'lookup', 'section' => 'Parentage'],
            ['key' => 'dam_id', 'label' => 'Dam Tag', 'type' => 'lookup', 'section' => 'Parentage'],
            ['key' => 'breed_purity', 'label' => 'Breed Purity', 'type' => 'select', 'section' => 'Parentage', 'help' => 'Set automatically when both parents are known.'],
            ['key' => 'cross_breeds', 'label' => 'Cross Details', 'type' => 'text', 'section' => 'Parentage', 'help' => 'For a crossbreed, for example Beetal × Teddy.'],
            ['key' => 'age_category', 'label' => 'Age Category', 'type' => 'select', 'section' => 'Classification', 'help' => 'Only when the birth date is unknown. Otherwise worked out from it and updated as the goat ages.'],
            ['key' => 'animal_purpose', 'label' => 'Animal Purpose', 'type' => 'select', 'section' => 'Classification'],
            ['key' => 'animal_class', 'label' => 'Goat Category', 'type' => 'select', 'section' => 'Classification', 'help' => 'Suggested from gender, age and purpose if left empty.'],
            ['key' => 'repro_status', 'label' => 'Female Status', 'type' => 'select', 'section' => 'Classification', 'help' => 'Reproductive status. Female goats only.'],
            ['key' => 'kid_stage', 'label' => 'Kid Stage', 'type' => 'select', 'section' => 'Classification'],
            // D-091: a bought doe may arrive pregnant: she then goes straight into Breeding with an expected kidding date.
            ['key' => 'pregnant_at_arrival', 'label' => 'Pregnant when bought?', 'type' => 'select', 'section' => 'Classification', 'help' => 'Female goats that were bought or transferred in.'],
            ['key' => 'pregnancy_days', 'label' => 'Days pregnant (about)', 'type' => 'number', 'section' => 'Classification', 'help' => 'Days since she was mated. Works out the expected kidding date (gestation about 150 days).'], // D-098
            ['key' => 'milking_at_arrival', 'label' => 'In milk when bought?', 'type' => 'select', 'section' => 'Classification', 'help' => 'A doe giving milk when she came to the farm starts her lactation in Milking.'], // D-117
            ['key' => 'days_in_milk', 'label' => 'Days since she kidded (about)', 'type' => 'number', 'section' => 'Classification', 'help' => 'Counts her days in milk.'], // D-117
            // D-066: off by default (new goats are Active; exits change it). The owner can switch it on in Settings.
            ['key' => 'lifecycle_status', 'label' => 'Lifecycle Status', 'type' => 'select', 'section' => 'Status', 'active' => false],
            ['key' => 'health_flag', 'label' => 'Health Flag', 'type' => 'select', 'section' => 'Status'],
            // D-091: arrival check, saved as a health record (and the body score with the first weighing).
            ['key' => 'body_score', 'label' => 'Body Condition (1–5)', 'type' => 'select', 'section' => 'Status'],
            ['key' => 'temperature_f', 'label' => 'Body Temperature (°F)', 'type' => 'number', 'section' => 'Status', 'help' => 'Normal 101.3–103.5 °F. Required for a pregnant goat. Outside normal, the vet is alerted.'], // D-093
            ['key' => 'pen_id', 'label' => 'Shed / Pen', 'type' => 'lookup', 'section' => 'Housing', 'required' => true],
            ['key' => 'ownership', 'label' => 'Ownership', 'type' => 'select', 'section' => 'Ownership', 'active' => false], // D-066
            ['key' => 'name', 'label' => 'Name (optional)', 'type' => 'text', 'section' => 'Notes'], // D-096: goats are known by their Tag ID
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
                'section' => $d['section'], 'sort' => ($i + 1) * 10, 'required' => $d['required'] ?? false, 'builtin' => true, 'active' => $d['active'] ?? true,
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
