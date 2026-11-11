<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * D-208 / BR-001: the permanent Animal ID, e.g. KKF-000125. One per goat, given once when the goat is created, never
 * changed (a database trigger refuses it) and never reused (the farm's counter only goes up, even when a goat leaves).
 * It is separate from the descriptive registration tag (farm_number), which can be corrected, and from the RFID EPC.
 */
class AnimalCode
{
    /** "KK Farms" → KKF: a word in capitals is kept whole, other words give their first letter. */
    public static function prefixFor(?string $name): string
    {
        $out = '';
        foreach (preg_split('/[^A-Za-z0-9]+/', (string) $name, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $out .= ctype_upper($w) && strlen($w) <= 3 ? $w : strtoupper($w[0]);
        }

        return substr($out, 0, 4) ?: 'GT';
    }

    public static function format(string $prefix, int $n): string
    {
        return $prefix.'-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    /** The next number for the current farm business. Atomic: the row update serialises concurrent registrations. */
    public static function next(): string
    {
        $row = DB::selectOne('UPDATE organizations SET animal_seq = animal_seq + 1 WHERE id = app_current_org() RETURNING animal_seq, animal_id_prefix, name');
        if (! $row) {
            throw new \RuntimeException('No farm business is selected.');
        }
        $prefix = $row->animal_id_prefix;
        if (! $prefix) {
            $prefix = self::prefixFor($row->name);
            DB::update('UPDATE organizations SET animal_id_prefix = ? WHERE id = app_current_org()', [$prefix]);
        }

        return self::format($prefix, (int) $row->animal_seq);
    }
}
