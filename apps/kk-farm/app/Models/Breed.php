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
        return self::formatTagId($this->code, $this->last_number + 1, $date, $origin);
    }

    /** D-060: origin letter in the Tag ID. */
    public const ORIGIN_LETTERS = ['purchased' => 'P', 'transferred_in' => 'T', 'born_on_farm' => 'B'];

    /**
     * D-061: breed code, date as DDMMYY, origin letter (P bought, T transferred, B born) and the breed's running
     * number with two digits, e.g. TD-141026-P-03 for a Teddy bought on 14 October 2026. The number keeps counting
     * per breed (it never restarts; after 99 it simply grows to 100), so a Tag ID is never reused. Without a known
     * origin the letter is left out.
     */
    public static function formatTagId(string $code, int $number, Carbon|string|null $date = null, ?string $origin = null): string
    {
        $letter = self::ORIGIN_LETTERS[$origin] ?? null;

        $day = ($date ? Carbon::parse($date) : (\App\Support\Tenant::current() ? \App\Support\Feed::now() : now()))->format('dmy'); // today on the farm's clock

        return sprintf('%s-%s-%s%02d', $code, $day, $letter ? "$letter-" : '', $number);
    }

    /**
     * Reserves and returns the next free Tag ID for this breed. The counter is incremented in one UPDATE,
     * so two people registering at the same moment never get the same number. Numbers already used by a
     * goat (for example typed by hand) are skipped.
     */
    public function issueTagId(Carbon|string|null $date = null, ?string $origin = null): string
    {
        return DB::transaction(function () use ($date, $origin) {
            do {
                $row = DB::selectOne('UPDATE breeds SET last_number = last_number + 1, updated_at = now() WHERE id = ? RETURNING code, last_number', [$this->id]);
                $tagId = self::formatTagId($row->code, $row->last_number, $date, $origin);
            } while (Animal::where('farm_number', $tagId)->exists());

            $this->last_number = $row->last_number;

            return $tagId;
        });
    }
}
