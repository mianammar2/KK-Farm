<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** D-082: weights are shown as KG. Only labels still at the old default change; a farm's own wording is kept. */
return new class extends Migration
{
    private const LABELS = ['birth_weight_kg' => ['Birth Weight Kg', 'Birth Weight (KG)'], 'latest_weight_kg' => ['Weight Kg', 'Weight (KG)']];

    public function up(): void
    {
        foreach (self::LABELS as $key => [$old, $new]) {
            DB::table('field_definitions')->where('key', $key)->where('label', $old)->update(['label' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::LABELS as $key => [$old, $new]) {
            DB::table('field_definitions')->where('key', $key)->where('label', $new)->update(['label' => $old]);
        }
    }
};
