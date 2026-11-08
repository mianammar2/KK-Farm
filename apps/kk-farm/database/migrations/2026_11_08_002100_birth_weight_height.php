<?php

use App\Support\FieldCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * D-063: the Birth section holds the birth date (or the estimated age of a bought goat, which fills an estimated
 * birth date), then weight, then the new height. Height is kept on the goat and on each weighing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animals', fn (Blueprint $t) => $t->decimal('height_cm', 5, 1)->nullable());
        Schema::table('weight_records', fn (Blueprint $t) => $t->decimal('height_cm', 5, 1)->nullable());

        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            DB::table('field_definitions')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'org_id' => $orgId, 'entity' => 'animal', 'key' => 'height_cm', 'label' => 'Height cm', 'type' => 'number',
                'section' => 'Birth', 'sort' => 0, 'required' => false, 'builtin' => true, 'active' => true,
                'help' => 'At the shoulder (withers). Saved with the first weighing.', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (FieldCatalog::defaults() as $i => $d) {
            DB::table('field_definitions')->where('builtin', true)->where('key', $d['key'])->update(['sort' => ($i + 1) * 10, 'section' => $d['section']]);
        }
        DB::table('field_definitions')->where('key', 'latest_weight_kg')->where('label', 'Latest Weight Kg')->update(['label' => 'Weight Kg']);
        DB::table('field_definitions')->where('builtin', false)->where('section', 'Housing and weight')->update(['section' => 'Housing']);
    }

    public function down(): void
    {
        DB::table('field_definitions')->where('builtin', false)->where('section', 'Housing')->update(['section' => 'Housing and weight']);
        DB::table('field_definitions')->where('key', 'latest_weight_kg')->where('label', 'Weight Kg')->update(['label' => 'Latest Weight Kg']);
        DB::table('field_definitions')->where('builtin', true)->whereIn('key', ['pen_id', 'latest_weight_kg'])->update(['section' => 'Housing and weight']);
        DB::table('field_definitions')->where('builtin', true)->where('key', 'latest_weight_kg')->update(['sort' => DB::raw('(select sort from field_definitions f2 where f2.org_id = field_definitions.org_id and f2.key = \'pen_id\') + 5')]);
        DB::table('field_definitions')->where('key', 'height_cm')->delete();
        Schema::table('weight_records', fn (Blueprint $t) => $t->dropColumn('height_cm'));
        Schema::table('animals', fn (Blueprint $t) => $t->dropColumn('height_cm'));
    }
};
