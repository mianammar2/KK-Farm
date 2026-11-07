<?php

use App\Models\Breed;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-041: Tag IDs are generated from the breed. Each breed has a short code and its own counter,
 * so Beetal goats are BT-0001, BT-0002 ... and Teddy goats TD-0001 ...
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('breeds', function (Blueprint $t) {
            $t->string('code', 8)->nullable();
            $t->unsignedInteger('last_number')->default(0); // last Tag ID number issued for this breed
        });

        // Existing breeds get a code, unique within their organization.
        foreach (DB::table('breeds')->orderBy('id')->get() as $breed) {
            DB::table('breeds')->where('id', $breed->id)->update(['code' => Breed::uniqueCode($breed->name, $breed->org_id, $breed->id)]);
        }

        DB::statement('ALTER TABLE breeds ALTER COLUMN code SET NOT NULL');
        Schema::table('breeds', fn (Blueprint $t) => $t->unique(['org_id', 'code']));

        // Existing register forms: Breed now comes before Tag ID (the Tag ID follows from it), with new help text.
        DB::table('field_definitions')->where('builtin', true)->where('key', 'tag_id')
            ->update(['sort' => 25, 'help' => 'Set automatically from the breed when you save (for example BT-0001). Type a number only to use a different one.']);
        DB::table('field_definitions')->where('builtin', true)->where('key', 'breed_id')->update(['sort' => 15]);
    }

    public function down(): void
    {
        Schema::table('breeds', function (Blueprint $t) {
            $t->dropUnique(['org_id', 'code']);
            $t->dropColumn(['code', 'last_number']);
        });
    }
};
