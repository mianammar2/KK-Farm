<?php

use App\Models\Breed;
use App\Support\FieldCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-046: KK Farms goat classification. Separate fields (Age Category, Animal Purpose, Goat Category,
 * Female Status, Kid Stage, Breed Purity), the plain-English categories with local terms, Pakistani breed labels.
 * Existing goats, fields and breeds are converted; nothing is deleted.
 */
return new class extends Migration
{
    private const CATEGORY_MAP = ['breeding_buck' => 'breeding_male', 'breeding_doe' => 'breeding_female', 'replacement_doe' => 'replacement_female', 'wether' => 'wether'];

    private const STATUS_MAP = ['na' => 'not_ready', 'open' => 'open', 'in_heat' => 'heat', 'bred' => 'mated', 'pregnant' => 'pregnant', 'lactating' => 'lactating', 'dry' => 'dry', 'postpartum' => 'kidded'];

    private const BREED_RENAMES = ['Makhi Cheeni' => 'Makhi Cheeni (Beetal Variety)', 'Dera Din Panah' => 'Dera Din Panah (DDP)', 'Mixed' => 'Crossbreed'];

    public function up(): void
    {
        Schema::table('animals', function (Blueprint $t) {
            $t->string('age_category')->nullable();
            $t->string('animal_purpose')->nullable();
            $t->string('kid_stage')->nullable();
            $t->string('breed_purity')->nullable();
            $t->string('cross_breeds', 120)->nullable();
        });

        // Goats: old categories and statuses to the new ones. A kid becomes Male Kid or Female Kid by its sex.
        foreach (self::CATEGORY_MAP as $old => $new) {
            DB::table('animals')->where('animal_class', $old)->update(['animal_class' => $new]);
        }
        DB::table('animals')->where('animal_class', 'kid')->where('sex', 'male')->update(['animal_class' => 'male_kid']);
        DB::table('animals')->where('animal_class', 'kid')->where('sex', 'female')->update(['animal_class' => 'female_kid']);
        DB::table('animals')->where('animal_class', 'other')->update(['animal_class' => null]);
        foreach (self::STATUS_MAP as $old => $new) {
            DB::table('animals')->where('repro_status', $old)->update(['repro_status' => $new]);
        }
        DB::table('animals')->where('sex', 'male')->update(['repro_status' => null]); // female status only

        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            // Breeds: Pakistani ERP labels, plus Gulabi and Barbari. Codes (and so Tag IDs) do not change.
            foreach (self::BREED_RENAMES as $old => $new) {
                if (! DB::table('breeds')->where('org_id', $orgId)->where('name', $new)->exists()) {
                    DB::table('breeds')->where('org_id', $orgId)->where('name', $old)->update(['name' => $new]);
                }
            }
            foreach (['Gulabi', 'Barbari'] as $name) {
                if (! DB::table('breeds')->where('org_id', $orgId)->where('name', $name)->exists()) {
                    DB::table('breeds')->insert(['org_id' => $orgId, 'name' => $name, 'code' => Breed::uniqueCode($name, $orgId), 'default_gestation_days' => 150, 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            // Fields: new ones are added, the standard ones get the new labels, options and order.
            FieldCatalog::seed($orgId);
            DB::table('field_definitions')->where('org_id', $orgId)->where('builtin', false)->increment('sort', 1000); // custom fields stay last
            $options = FieldCatalog::options();
            foreach (FieldCatalog::defaults() as $i => $d) {
                $changes = ['sort' => ($i + 1) * 10, 'section' => $d['section'], 'updated_at' => now()];
                if (in_array($d['key'], ['animal_class', 'repro_status', 'sex'], true)) {
                    $changes += ['label' => $d['label'], 'help' => $d['help'] ?? null];
                }
                if (in_array($d['key'], ['animal_class', 'repro_status'], true)) {
                    $changes['options'] = json_encode(collect($options[$d['key']])->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values());
                }
                DB::table('field_definitions')->where('org_id', $orgId)->where('builtin', true)->where('key', $d['key'])->update($changes);
            }
        }
    }

    public function down(): void
    {
        Schema::table('animals', fn (Blueprint $t) => $t->dropColumn(['age_category', 'animal_purpose', 'kid_stage', 'breed_purity', 'cross_breeds']));
        // Category, status, breed and field relabelling are kept: they carry no information loss worth reversing.
    }
};
