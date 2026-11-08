<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-066: the register shows only the health flag under Status; Lifecycle Status and Ownership are switched off
 * (not deleted: existing values stay, and the owner can switch them on again in Settings → Register fields).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('field_definitions')->where('builtin', true)->whereIn('key', ['lifecycle_status', 'ownership'])->update(['active' => false]);
    }

    public function down(): void
    {
        DB::table('field_definitions')->where('builtin', true)->whereIn('key', ['lifecycle_status', 'ownership'])->update(['active' => true]);
    }
};
