<?php

namespace App\Support;

use App\Models\TeethStage;
use Illuminate\Support\Collection;

/**
 * D-220: the farm's teeth stages (aging by permanent lower incisors, D-091). Kept in Settings → Age Groups; a farm with
 * none yet gets the KK Farms defaults on first use.
 */
final class Teeth
{
    /** key => [label, from, to, estimate] */
    public const DEFAULTS = [ // D-221: KK Farms age & teeth table
        'milk' => ['Milk Teeth', 0, 14, 8],
        '2' => ['2 Permanent Teeth', 14, 18, 16],
        '4' => ['4 Permanent Teeth', 18, 24, 21],
        '6' => ['6 Permanent Teeth', 24, 30, 27],
        '8' => ['8 Permanent Teeth', 30, 48, 39],
        'worn' => ['Broken Teeth', 48, null, 54],
    ];

    /** @return Collection<int, TeethStage> */
    public static function stages(): Collection
    {
        return Tenant::memo('teeth_stages', function () {
            if (! Tenant::current()) {
                return collect(self::DEFAULTS)->map(fn ($d, $k) => new TeethStage(['key' => (string) $k, 'label' => $d[0], 'from_months' => $d[1], 'to_months' => $d[2], 'estimate_months' => $d[3]]))->values();
            }
            if (! TeethStage::exists()) {
                $i = 0;
                foreach (self::DEFAULTS as $k => $d) {
                    TeethStage::create(['key' => (string) $k, 'label' => $d[0], 'from_months' => $d[1], 'to_months' => $d[2], 'estimate_months' => $d[3], 'sort' => ($i += 10)]);
                }
            }

            return TeethStage::orderBy('sort')->orderBy('from_months')->get();
        });
    }

    /** @return array<string, string> key => "2 teeth (12–18 months)" for dropdowns */
    public static function options(): array
    {
        return self::stages()->mapWithKeys(fn (TeethStage $s) => [$s->key => $s->longLabel()])->all();
    }

    /** @return array<string, string> key => "2 teeth" */
    public static function short(): array
    {
        return self::stages()->mapWithKeys(fn (TeethStage $s) => [$s->key => $s->label])->all();
    }

    /** @return array<string, int> key => months used to estimate the birth date */
    public static function months(): array
    {
        return self::stages()->mapWithKeys(fn (TeethStage $s) => [$s->key => $s->estimate_months])->all();
    }
}
