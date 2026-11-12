<?php

namespace App\Support;

use App\Models\TeethStage;
use Illuminate\Support\Collection;

/**
 * D-220 / D-229: the farm's dental codes (permanent lower incisors). Teeth are an examined fact, kept apart from age:
 * a 13-month goat with two permanent teeth is GRW-T02, not an adult. No examination = UNK. A farm with none yet gets
 * the KK Farms codes on first use. The ages are only a guide (used to estimate a bought goat's birth date).
 */
final class Teeth
{
    /** code => [classification, Pakistan farm term, from months, to months, months used to estimate the birth date] */
    public const DEFAULTS = [
        'MLT' => ['Milk Teeth', 'Kheeri', 0, 12, 8],
        'T02' => ['2 Permanent Incisors', 'Donda', 12, 18, 15],
        'T04' => ['4 Permanent Incisors', 'Choga / Char Dant', 18, 30, 24],
        'T06' => ['6 Permanent Incisors', 'Chhay Dant', 24, 42, 33],
        'T08' => ['8 Permanent Incisors', 'Aath Dant / Full Mouth', 36, null, 42],
    ];

    public const UNKNOWN = 'UNK';

    /** D-229: recorded apart from the incisor count (8 teeth can still have 2 broken). */
    public const CONDITIONS = ['normal' => 'Normal', 'worn' => 'Worn', 'broken' => 'Broken', 'missing' => 'Missing', 'abnormal' => 'Abnormal'];

    /** @return Collection<int, TeethStage> */
    public static function stages(): Collection
    {
        return Tenant::memo('teeth_stages', function () {
            if (! Tenant::current()) {
                return collect(self::DEFAULTS)->map(fn ($d, $k) => new TeethStage(['key' => (string) $k, 'label' => $d[0], 'local_term' => $d[1], 'from_months' => $d[2], 'to_months' => $d[3], 'estimate_months' => $d[4]]))->values();
            }
            if (! TeethStage::exists()) {
                $i = 0;
                foreach (self::DEFAULTS as $k => $d) {
                    TeethStage::create(['key' => (string) $k, 'label' => $d[0], 'local_term' => $d[1], 'from_months' => $d[2], 'to_months' => $d[3], 'estimate_months' => $d[4], 'sort' => ($i += 10)]);
                }
            }

            return TeethStage::orderBy('sort')->orderBy('from_months')->get();
        });
    }

    /** @return array<string, string> code => "T02 · 2 Permanent Incisors (Donda) · about 12–18 months" for dropdowns */
    public static function options(): array
    {
        return self::stages()->mapWithKeys(fn (TeethStage $s) => [$s->key => $s->longLabel()])->all();
    }

    /** @return array<string, string> code => "2 Permanent Incisors" */
    public static function short(): array
    {
        return self::stages()->mapWithKeys(fn (TeethStage $s) => [$s->key => $s->label])->all();
    }

    /** @return array<string, int> code => months used to estimate the birth date */
    public static function months(): array
    {
        return self::stages()->mapWithKeys(fn (TeethStage $s) => [$s->key => $s->estimate_months])->all();
    }

    /** "T02", or "UNK" when the teeth were never examined. */
    public static function code(?string $teeth): string
    {
        return $teeth ?: self::UNKNOWN;
    }
}
