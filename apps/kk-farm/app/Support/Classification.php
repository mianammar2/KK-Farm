<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * KK Farms goat classification (D-046), in plain English with the Pakistani farm term alongside.
 *
 * The classification is several separate fields instead of one long dropdown: Gender, Age Category, Breed,
 * Animal Purpose, Goat Category, Female Status and Kid Stage. A one-line "generated classification" is built
 * from them, for example "Breeding Female (Doe) · Makhi Cheeni (Beetal Variety) · Breeding · Pregnant".
 */
final class Classification
{
    /** Goat Category: value => [ERP label, local term, sex it applies to (null = both)]. */
    public const CATEGORIES = [
        'breeding_male' => ['Breeding Male (Buck)', 'Nasli Bakra', 'male'],
        'breeding_female' => ['Breeding Female (Doe)', 'Nasli Bakri', 'female'],
        'replacement_male' => ['Replacement Breeding Male', 'Future Breeder Bakra', 'male'],
        'replacement_female' => ['Replacement Breeding Female', 'Future Breeder Bakri', 'female'],
        'male_kid' => ['Male Kid', 'Nar Bacha', 'male'],
        'female_kid' => ['Female Kid', 'Mada Bachi', 'female'],
        'growing_male' => ['Growing Male Goat', 'Growing Bakra', 'male'],
        'growing_female' => ['Growing Female Goat', 'Growing Bakri', 'female'],
        'fattening_male' => ['Fattening Male Goat', 'Qurbani / Fattening Bakra', 'male'],
        'wether' => ['Castrated Male (Wether)', 'Khassi Bakra', 'male'],
        'cull' => ['Cull Goat', 'Sale / Rejected Goat', null],
    ];

    /** Female Status (reproductive status). Only for female goats. */
    public const FEMALE_STATUSES = [
        'not_ready' => 'Not Ready for Breeding', 'ready' => 'Ready for Breeding', 'heat' => 'Heat Detected',
        'mated' => 'Mating Completed', 'check_due' => 'Pregnancy Check Due', 'pregnant' => 'Pregnant',
        'expected_kidding' => 'Expected Kidding', 'kidded' => 'Kidding Completed', 'lactating' => 'Lactating Female',
        'nursing' => 'Nursing Female', 'dry' => 'Dry Female', 'open' => 'Open / Non-Pregnant',
        'hold' => 'Breeding Hold', 'retired' => 'Retired from Breeding',
    ];

    public const AGE_CATEGORIES = ['kid' => 'Kid', 'grower' => 'Grower', 'adult' => 'Adult'];

    /** Default months at which a goat counts as a grower, then an adult (KK Farms). Each farm can change them. */
    public const GROWER_FROM_MONTHS = 3;

    public const ADULT_FROM_MONTHS = 12;

    /** @var array<string, array{0: int, 1: int}> */
    private static array $cache = [];

    /** @return array{0: int, 1: int} [grower from, adult from] in months, for the current organization */
    public static function boundaries(): array
    {
        $orgId = Tenant::current();
        if (! $orgId) {
            return [self::GROWER_FROM_MONTHS, self::ADULT_FROM_MONTHS];
        }

        return self::$cache[$orgId] ??= (function () use ($orgId) {
            $org = \App\Models\Organization::find($orgId);

            return [(int) ($org->grower_from_months ?? self::GROWER_FROM_MONTHS), (int) ($org->adult_from_months ?? self::ADULT_FROM_MONTHS)];
        })();
    }

    public static function forgetBoundaries(): void
    {
        self::$cache = [];
    }

    public const PURPOSES = ['breeding' => 'Breeding', 'replacement' => 'Replacement Breeding', 'fattening' => 'Fattening / Qurbani', 'sale' => 'Sale', 'milk' => 'Milk'];

    /** Kid Stage; shown with the kid's sex in the generated classification (Newborn Male Kid ...). */
    public const KID_STAGES = [
        'newborn' => 'Newborn', 'suckling' => 'Suckling', 'weaned' => 'Weaned',
        'breeding_selected' => 'Breeding Selected', 'sale_selected' => 'Sale Selected', 'orphan' => 'Orphan',
    ];

    public const PURITY = ['pure' => 'Purebred', 'first_cross' => 'Crossbreed (first cross)', 'later_cross' => 'Crossbreed (later cross)', 'unknown' => 'Unknown'];

    /** Male / female statuses that the old prototype fields map to. */
    public const LEGACY_REPRO = ['pregnant' => 'pregnant', 'expected_kidding' => 'pregnant', 'lactating' => 'lactating', 'nursing' => 'lactating'];

    /** @return array<string, string> value => "ERP label (local term)" for dropdowns */
    public static function categoryOptions(): array
    {
        return array_map(fn (array $c) => "{$c[0]} ({$c[1]})", self::CATEGORIES);
    }

    /** @return array<string, string> category value => sex, for categories that fit one sex only */
    public static function categorySex(): array
    {
        return array_filter(array_map(fn (array $c) => $c[2], self::CATEGORIES));
    }

    public static function ageCategory(Carbon|string|null $birthDate): ?string
    {
        if (! $birthDate) {
            return null;
        }
        $months = Carbon::parse($birthDate)->diffInMonths(now());
        [$grower, $adult] = self::boundaries();

        return match (true) {
            $months < $grower => 'kid',
            $months < $adult => 'grower',
            default => 'adult',
        };
    }

    /** The category that follows from sex, age and purpose, when the user has not chosen one. */
    public static function suggestCategory(?string $sex, ?string $age, ?string $purpose): ?string
    {
        if (! in_array($sex, ['male', 'female'], true) || ! $age) {
            return null;
        }
        $m = $sex === 'male';

        return match (true) {
            $age === 'kid' => $m ? 'male_kid' : 'female_kid',
            $purpose === 'fattening' && $m => 'fattening_male',
            $age === 'grower' && in_array($purpose, ['breeding', 'replacement'], true) => $m ? 'replacement_male' : 'replacement_female',
            $age === 'grower' => $m ? 'growing_male' : 'growing_female',
            $purpose === 'replacement' => $m ? 'replacement_male' : 'replacement_female',
            in_array($purpose, ['breeding', 'milk', null], true) => $m ? 'breeding_male' : 'breeding_female',
            default => $m ? 'growing_male' : 'growing_female',
        };
    }

    /** "Breeding Female (Doe) · Makhi Cheeni · Breeding · Pregnant" */
    public static function summary(?string $category, ?string $breed, ?string $purpose, ?string $status, ?string $kidStage = null, ?string $sex = null): string
    {
        $head = self::CATEGORIES[$category][0] ?? null;
        if ($kidStage && in_array($category, ['male_kid', 'female_kid'], true)) {
            $head = self::KID_STAGES[$kidStage].' '.self::CATEGORIES[$category][0];
        }

        return collect([$head, $breed, self::PURPOSES[$purpose] ?? null, $sex === 'female' ? (self::FEMALE_STATUSES[$status] ?? null) : null])->filter()->implode(' · ');
    }
}
