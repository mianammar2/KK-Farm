<?php

namespace App\Support;

use App\Models\Animal;

/**
 * D-230: the framework's sex-wise production labels (KK Farms classification, phase 2). They are generated from the
 * goat's facts, not chosen from a list: one main role (Breeding Doe, Grower Buck …) plus performance and production
 * designations that can apply at the same time (a Breeding Doe can also be a Proven Dam and a Dairy Doe).
 */
final class Designations
{
    /** code => [label, sex, business role, main (true) or added (false)] */
    public const CODES = [
        'BK' => ['Buck Kid', 'male', 'Young male', true],
        'GB' => ['Grower Buck', 'male', 'Growing male', true],
        'RB' => ['Replacement Buck', 'male', 'Selected future breeder', true],
        'BB' => ['Breeding Buck', 'male', 'Breeding male', true],
        'FM' => ['Fattening Male', 'male', 'Meat production', true],
        'WTH' => ['Wether', 'male', 'Castrated male', true],
        'CM' => ['Cull Male', 'male', 'Removed from productive use', true],
        'PS' => ['Proven Sire', 'male', 'Breeder with recorded offspring', false],
        'DK' => ['Doe Kid', 'female', 'Young female', true],
        'GD' => ['Grower Doe', 'female', 'Growing female', true],
        'RD' => ['Replacement Doe', 'female', 'Selected future breeder', true],
        'BD' => ['Breeding Doe', 'female', 'Breeding female', true],
        'FF' => ['Fattening Female', 'female', 'Meat production', true],
        'CF' => ['Cull Female', 'female', 'Removed from productive use', true],
        'PD' => ['Proven Dam', 'female', 'Female with recorded offspring', false],
        'DD' => ['Dairy Doe', 'female', 'Milk-producing role', false],
    ];

    /** Goat category (D-046) => main role, per sex */
    private const FROM_CATEGORY = [
        'male_kid' => 'BK', 'female_kid' => 'DK', 'growing_male' => 'GB', 'growing_female' => 'GD',
        'replacement_male' => 'RB', 'replacement_female' => 'RD', 'breeding_male' => 'BB', 'breeding_female' => 'BD',
        'fattening_male' => 'FM', 'wether' => 'WTH',
    ];

    public static function castrated(Animal $a): bool
    {
        return $a->sex === 'male' && ($a->castrated_on !== null || $a->animal_class === 'wether');
    }

    /** The one main role (adds up to the herd total), or null when sex or age is unknown. */
    public static function main(Animal $a): ?string
    {
        $m = $a->sex === 'male';
        if (! in_array($a->sex, ['male', 'female'], true)) {
            return null;
        }
        if (self::castrated($a)) {
            return 'WTH';
        }
        $cat = $a->effectiveCategory();
        if ($cat === 'cull') {
            return $m ? 'CM' : 'CF';
        }
        if (! in_array($cat, ['male_kid', 'female_kid'], true) && in_array($a->animal_purpose, ['fattening', 'meat'], true)) {
            return $m ? 'FM' : 'FF';
        }

        return self::FROM_CATEGORY[$cat] ?? null;
    }

    /** @return list<string> main role first, then the designations that also apply (PS, PD, DD) */
    public static function codes(Animal $a): array
    {
        $codes = array_filter([self::main($a)]);
        $proven = self::proven();
        if ($a->sex === 'male' && ! self::castrated($a) && isset($proven['sires'][$a->id])) {
            $codes[] = 'PS';
        }
        if ($a->sex === 'female' && isset($proven['dams'][$a->id])) {
            $codes[] = 'PD';
        }
        if ($a->sex === 'female' && ($a->animal_purpose === 'milk' || isset($proven['milking'][$a->id]))) {
            $codes[] = 'DD';
        }

        return array_values($codes);
    }

    /** @return array<string, string> code => "BD · Breeding Doe" */
    public static function labels(array $codes): array
    {
        return collect($codes)->mapWithKeys(fn ($c) => [$c => self::CODES[$c][0] ?? $c])->all();
    }

    /** Sires and dams with recorded offspring (a registered kid or a kidding), and does in an open lactation. */
    private static function proven(): array
    {
        return Tenant::memo('designations_proven', fn () => [
            'sires' => array_flip(Animal::whereNotNull('sire_id')->distinct()->pluck('sire_id')->all()),
            'dams' => array_flip(array_merge(Animal::whereNotNull('dam_id')->distinct()->pluck('dam_id')->all(), \App\Models\Kidding::where('born_alive', '>', 0)->distinct()->pluck('dam_id')->all())),
            'milking' => array_flip(\App\Models\Lactation::whereNull('dried_on')->pluck('animal_id')->all()),
        ]);
    }

    /** Validation rule: a castrated male (or a female) can never be chosen as a sire. */
    public static function sireRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $sire = $value ? Animal::find($value) : null;
            if ($sire && $sire->sex !== 'male') {
                $fail('The sire must be a male goat.');
            } elseif ($sire && self::castrated($sire)) {
                $fail("{$sire->farm_number} is castrated (wether) and cannot be a sire.");
            }
        };
    }
}
