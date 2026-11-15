<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-328: texts saved before dates were digits only (D-277) still say "since 24 Oct": rewrite "24 Oct" as "24/10" in the
 * stored health and event texts (titles, summaries, notes). Only that pattern changes; the old text is not needed back.
 */
return new class extends Migration
{
    private const COLS = ['health_records' => ['title', 'notes'], 'health_cases' => ['condition', 'notes'], 'tasks' => ['title', 'notes']]; // not animal_events: history stays as written; it is shown with digits (Dates::digits)

    public function up(): void
    {
        $months = ['Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04', 'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08', 'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12'];
        $re = '/\\b([0-3]?\\d) ('.implode('|', array_keys($months)).')\\b/';
        foreach (self::COLS as $table => $cols) {
            foreach ($cols as $col) {
                // "4 Nov" / "24 Oct" (a day, a space, a month name) → "04/11" / "24/10"
                DB::table($table)->where($col, '~', '[0-3]?[0-9] (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)')->orderBy('id')->each(function ($row) use ($table, $col, $re, $months) {
                    $new = preg_replace_callback($re, fn ($m) => str_pad($m[1], 2, '0', STR_PAD_LEFT).'/'.$months[$m[2]], (string) $row->{$col});
                    if ($new !== $row->{$col}) {
                        DB::table($table)->where('id', $row->id)->update([$col => $new]);
                    }
                });
            }
        }
    }

    public function down(): void
    {
        // not reversible: the digit form is the one the farm uses
    }
};
