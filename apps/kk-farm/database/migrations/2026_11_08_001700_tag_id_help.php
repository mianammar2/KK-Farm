<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** D-059: Tag ID help text for the new format, only where the farm kept the default text. */
return new class extends Migration
{
    private const OLD = 'Set automatically from the breed when you save (for example BT-0001). Type a number only to use a different one.';

    private const NEW = 'Set automatically: breed code, purchase date (birth date for farm-born) and number, for example TD-14-10-26-0003. Type one only to use a different Tag ID.';

    public function up(): void
    {
        DB::table('field_definitions')->where('key', 'tag_id')->where('help', self::OLD)->update(['help' => self::NEW]);
    }

    public function down(): void
    {
        DB::table('field_definitions')->where('key', 'tag_id')->where('help', self::NEW)->update(['help' => self::OLD]);
    }
};
