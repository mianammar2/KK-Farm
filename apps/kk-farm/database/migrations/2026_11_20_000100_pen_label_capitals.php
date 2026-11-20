<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit: on-screen text says PEN. The housing field kept its old default label "Shed / Pen" from before that rule;
 * only rows still holding exactly that default change, so a label a farm wrote itself stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->each(fn () => DB::table('field_definitions')->where('key', 'pen_id')->where('label', 'Shed / Pen')->update(['label' => 'Shed / PEN']));
    }

    public function down(): void
    {
        $this->each(fn () => DB::table('field_definitions')->where('key', 'pen_id')->where('label', 'Shed / PEN')->update(['label' => 'Shed / Pen']));
    }

    /** Row-level security: one organization at a time, as the other data migrations do. */
    private function each(callable $fn): void
    {
        foreach (DB::table('organizations')->pluck('id') as $org) {
            DB::select("select set_config('app.current_org', ?, false)", [$org]);
            $fn();
        }
        DB::select("select set_config('app.current_org', '', false)");
    }
};
