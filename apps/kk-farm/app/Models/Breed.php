<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A breed, with the short code its Tag IDs start with (D-041): Beetal → BT-0001, BT-0002 ...
 */
class Breed extends Model
{
    protected $guarded = [];

    /** Codes for the KK Farms workbook breeds. Other breeds get initials (Dera Din Panah → DDP) or two letters. */
    public const CODES = [
        'Beetal' => 'BT', 'Teddy' => 'TD', 'Makhi Cheeni' => 'MC', 'Makhi Cheeni (Beetal Variety)' => 'MC', 'Kamori' => 'KM', 'Nachi' => 'NC',
        'Dera Din Panah' => 'DDP', 'Dera Din Panah (DDP)' => 'DDP', 'Mixed' => 'MX', 'Crossbreed' => 'MX', 'Other' => 'OT',
        'Gulabi' => 'GB', 'Barbari' => 'BB', 'Crossbred' => 'CB', 'Boer' => 'BR',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $breed): void {
            $breed->code = $breed->code
                ? self::normaliseCode($breed->code)
                : self::uniqueCode($breed->name, $breed->org_id ?? DB::selectOne('select app_current_org() as v')->v);
        });
    }

    public static function normaliseCode(string $code): string
    {
        return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($code)), 0, 8);
    }

    /** A code for the breed name that no other breed of the organization uses. */
    public static function uniqueCode(string $name, ?string $orgId, ?int $ignoreId = null): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $base = self::CODES[trim($name)]
            ?? (count($words) > 1 ? collect($words)->map(fn ($w) => Str::substr($w, 0, 1))->implode('') : Str::substr($name, 0, 2));
        $base = self::normaliseCode($base) ?: 'GT';

        $taken = DB::table('breeds')->where('org_id', $orgId)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->pluck('code')->filter()->all();
        $code = $base;
        for ($n = 2; in_array($code, $taken, true); $n++) {
            $code = substr($base, 0, 6).$n;
        }

        return $code;
    }

    /** The Tag ID the next goat of this breed would get. A preview only: the number is reserved on save. */
    public function nextTagId(Carbon|string|null $date = null, ?string $origin = null): string
    {
        $prefix = self::tagPrefix($this->code, $date, $origin);

        return $prefix.sprintf('%02d', (self::usedNumbers()[$this->code][substr($prefix, strlen($this->code) + 1, -1)] ?? 0) + 1);
    }

    /**
     * D-291: the highest number used so far for each breed, date and origin, e.g. ['MC' => ['101026P' => 18, '071126P' => 1]],
     * so the Register form can show the Tag ID a goat will get for the date chosen. Removed goats count: a number is never given twice.
     *
     * @return array<string, array<string, int>>
     */
    public static function usedNumbers(): array
    {
        $out = [];
        foreach (Animal::withoutGlobalScopes()->pluck('farm_number') as $t) {
            if (preg_match('/^([A-Z0-9]+)-(\d{6}[A-Z]?)-(\d+)$/', (string) $t, $m)) {
                $out[$m[1]][$m[2]] = max($out[$m[1]][$m[2]] ?? 0, (int) $m[3]);
            }
        }

        return $out;
    }

    /** D-060: origin letter in the Tag ID. */
    public const ORIGIN_LETTERS = ['purchased' => 'P', 'transferred_in' => 'T', 'born_on_farm' => 'B'];

    /**
     * D-061: breed code, date as DDMMYY, origin letter (P bought, T transferred, B born) and a two-digit number, e.g.
     * TD-141026P-03 for the third Teddy bought on 14 October 2026. D-291 (owner's rule): the number counts the goats of that
     * breed, date and origin, so the first goat of a day is -01 and it starts again at 01 the next day. Without a known origin
     * the letter is left out.
     */
    public static function formatTagId(string $code, int $number, Carbon|string|null $date = null, ?string $origin = null): string
    {
        return self::tagPrefix($code, $date, $origin).sprintf('%02d', $number);
    }

    /** "TD-141026P-": everything before the number. */
    public static function tagPrefix(string $code, Carbon|string|null $date = null, ?string $origin = null): string
    {
        $letter = self::ORIGIN_LETTERS[$origin] ?? null;
        $day = ($date ? Carbon::parse($date) : (\App\Support\Tenant::current() ? \App\Support\Feed::now() : now()))->format('dmy'); // today on the farm's clock

        return sprintf('%s-%s%s-', $code, $day, $letter ?? ''); // D-062: date and origin joined
    }

    /**
     * Reserves and returns the next free Tag ID for this breed, date and origin: one more than the highest number already used
     * for them, by any goat ever registered (removed goats included), so a Tag ID is never given twice. A transaction lock on
     * the prefix means two people registering at the same moment never get the same number.
     */
    public function issueTagId(Carbon|string|null $date = null, ?string $origin = null): string
    {
        return DB::transaction(function () use ($date, $origin) {
            $prefix = self::tagPrefix($this->code, $date, $origin);
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$prefix]);
            $used = Animal::withoutGlobalScopes()->where('farm_number', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $prefix).'%')->pluck('farm_number')
                ->map(fn ($t) => preg_match('/-(\d+)$/', $t, $m) ? (int) $m[1] : 0)->max() ?? 0;
            $n = $used;
            do {
                $tagId = $prefix.sprintf('%02d', ++$n);
            } while (Animal::withoutGlobalScopes()->where('farm_number', $tagId)->exists());

            return $tagId;
        });
    }
}
