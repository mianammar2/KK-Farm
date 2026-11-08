<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** D-061: Tag ID help text for the compact format (only where the farm kept the default text). */
return new class extends Migration
{
    private const OLD = 'Set automatically: breed code, origin (P bought, T transferred, B born), purchase or birth date and number, for example TD-P-14-10-26-0003. Type one only to use a different Tag ID.';

    private const NEW = 'Set automatically: breed code, date (DDMMYY: purchase or birth date), origin (P bought, T transferred, B born) and number, for example TD-141026-P-03. Type one only to use a different Tag ID.';

    public function up(): void
    {
        DB::table('field_definitions')->where('key', 'tag_id')->where('help', self::OLD)->update(['help' => self::NEW]);
    }

    public function down(): void
    {
        DB::table('field_definitions')->where('key', 'tag_id')->where('help', self::NEW)->update(['help' => self::OLD]);
    }
};
