<?php

use App\Support\FieldCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-058: the goat register starts with Origin Type, then Breed, Purchase Date and the Tag ID from the breed.
 * Built-in fields get the new order and section; a label is only changed if the farm kept the old default.
 * Custom fields move to the renamed section and come after the built-in ones.
 */
return new class extends Migration
{
    private const SECTION_RENAMES = ['Identity' => 'Origin and identity', 'Birth and origin' => 'Birth', 'Ownership and cost' => 'Ownership'];

    /** The order before this migration, for rollback: key => [sort, section]. */
    private const OLD = [
        'rfid_epc' => [10, 'Identity'], 'breed_id' => [20, 'Identity'], 'tag_id' => [30, 'Identity'], 'name' => [40, 'Identity'], 'sex' => [50, 'Identity'],
        'birth_date' => [60, 'Birth and origin'], 'birth_date_is_estimate' => [70, 'Birth and origin'], 'birth_weight_kg' => [80, 'Birth and origin'],
        'origin' => [90, 'Birth and origin'], 'acquired_on' => [100, 'Birth and origin'], 'source_farm' => [110, 'Birth and origin'],
        'sire_id' => [120, 'Parentage'], 'dam_id' => [130, 'Parentage'], 'breed_purity' => [140, 'Parentage'], 'cross_breeds' => [150, 'Parentage'],
        'age_category' => [160, 'Classification'], 'animal_purpose' => [170, 'Classification'], 'animal_class' => [180, 'Classification'],
        'repro_status' => [190, 'Classification'], 'kid_stage' => [200, 'Classification'], 'lifecycle_status' => [210, 'Status'], 'health_flag' => [220, 'Status'],
        'pen_id' => [230, 'Housing and weight'], 'latest_weight_kg' => [240, 'Housing and weight'], 'purchase_cost_pkr' => [250, 'Ownership and cost'],
        'ownership' => [260, 'Ownership and cost'], 'notes' => [270, 'Notes'],
    ];

    public function up(): void
    {
        foreach (FieldCatalog::defaults() as $i => $d) {
            DB::table('field_definitions')->where('builtin', true)->where('key', $d['key'])->update(['sort' => ($i + 1) * 10, 'section' => $d['section']]);
        }
        DB::table('field_definitions')->where('builtin', true)->where('key', 'acquired_on')->where('label', 'Acquired Date')->update(['label' => 'Purchase Date', 'help' => 'The day the goat arrived on the farm.']);
        foreach (self::SECTION_RENAMES as $old => $new) {
            DB::table('field_definitions')->where('builtin', false)->where('section', $old)->update(['section' => $new]);
        }
        DB::table('field_definitions')->where('builtin', false)->where('sort', '<', 1000)->update(['sort' => DB::raw('sort + 1000')]);
    }

    public function down(): void
    {
        foreach (self::OLD as $key => [$sort, $section]) {
            DB::table('field_definitions')->where('builtin', true)->where('key', $key)->update(['sort' => $sort, 'section' => $section]);
        }
        DB::table('field_definitions')->where('builtin', true)->where('key', 'acquired_on')->where('label', 'Purchase Date')->update(['label' => 'Acquired Date', 'help' => null]);
        foreach (self::SECTION_RENAMES as $old => $new) {
            DB::table('field_definitions')->where('builtin', false)->where('section', $new)->update(['section' => $old]);
        }
        DB::table('field_definitions')->where('builtin', false)->where('sort', '>=', 1000)->update(['sort' => DB::raw('sort - 1000')]);
    }
};
