<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** D-093: the farm takes body temperature in °F. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('field_definitions')->where('key', 'temperature_c')->where('builtin', true)->update([
            'key' => 'temperature_f', 'label' => 'Body Temperature (°F)',
            'help' => 'Normal 101.3–103.5 °F. Required for a pregnant goat. Outside normal, the vet is alerted.',
        ]);
    }

    public function down(): void
    {
        DB::table('field_definitions')->where('key', 'temperature_f')->where('builtin', true)->update([
            'key' => 'temperature_c', 'label' => 'Body Temperature (°C)', 'help' => 'Normal 38.5–39.7 °C. Above 39.7 °C is a fever: tell the vet.',
        ]);
    }
};
