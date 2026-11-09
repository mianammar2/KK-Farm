<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** D-096: goats are known by their Tag ID; a name is optional and asked last, next to the notes. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('field_definitions')->where('key', 'notes')->where('builtin', true)->get(['org_id', 'sort']) as $notes) {
            DB::table('field_definitions')->where('org_id', $notes->org_id)->where('key', 'name')->where('builtin', true)
                ->update(['section' => 'Notes', 'sort' => $notes->sort - 1, 'label' => 'Name (optional)']);
        }
    }

    public function down(): void
    {
        foreach (DB::table('field_definitions')->where('key', 'sex')->where('builtin', true)->get(['org_id', 'sort']) as $sex) {
            DB::table('field_definitions')->where('org_id', $sex->org_id)->where('key', 'name')->where('builtin', true)
                ->update(['section' => 'Origin and identity', 'sort' => $sex->sort + 5, 'label' => 'Animal Name']);
        }
    }
};
