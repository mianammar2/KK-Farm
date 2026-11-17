<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * D-335: item codes are made from the name when the user leaves Code blank.
 * "Actibol Injection" -> ACTIBOL, "Almox LA Injection" -> ALMOX-LA; a taken code gets -2, -3 ...
 * The same rule runs in the browser (StockForms.tsx codeFromName) to show the code before saving.
 */
class ItemCode
{
    public const FILLER = ['INJECTION', 'INJ', 'POWDER', 'TABLET', 'TABLETS', 'TAB', 'BOLUS', 'SPRAY', 'SOLUTION', 'ORAL', 'LIQUID',
        'SUSPENSION', 'CREAM', 'OINTMENT', 'SYRUP', 'DROPS', 'GEL', 'FOR', 'AND', 'THE', 'OF', 'WITH'];

    public static function fromName(string $name): string
    {
        $words = array_values(array_filter(preg_split('/[^A-Z0-9]+/', strtoupper($name)) ?: [], fn ($w) => $w !== ''));
        $kept = array_values(array_filter($words, fn ($w) => ! in_array($w, self::FILLER, true)));
        $words = $kept ?: $words;
        if (! $words) {
            return '';
        }
        $code = substr($words[0], 0, 10);
        if (isset($words[1])) {
            $code .= '-'.substr($words[1], 0, 6);
        }

        return $code;
    }

    /** A free code in $table (tenant-scoped by RLS); $prefix is used when the name gives nothing. */
    public static function make(string $table, string $name, string $prefix): string
    {
        $base = self::fromName($name) ?: $prefix;
        $taken = DB::table($table)->whereRaw('upper(code) like ?', [$base.'%'])->pluck('code')->map(fn ($c) => strtoupper((string) $c))->flip();
        if (! isset($taken[$base])) {
            return $base;
        }
        for ($n = 2; ; $n++) {
            $code = substr($base, 0, 20 - strlen("-$n"))."-$n";
            if (! isset($taken[$code])) {
                return $code;
            }
        }
    }

    /** Fill a blank code on the request before validation. */
    public static function fill(\Illuminate\Http\Request $request, string $table, string $prefix): void
    {
        $code = strtoupper(trim((string) $request->input('code')));
        $request->merge(['code' => $code !== '' ? $code : self::make($table, (string) $request->input('name'), $prefix)]);
    }
}
