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

    /**
     * D-235: picking a role code in the registry's Gender field fills the rest:
     * code => [gender, purpose, goat category]. PS / PD are declared for bought proven breeders; DD sets milk.
     */
    public const PICK = [
        'BK' => ['male', null, 'male_kid'], 'GB' => ['male', null, 'growing_male'], 'RB' => ['male', 'replacement', 'replacement_male'],
        'BB' => ['male', 'breeding', 'breeding_male'], 'PS' => ['male', 'breeding', 'breeding_male'], 'FM' => ['male', 'fattening', 'fattening_male'],
        'WTH' => ['male', 'fattening', 'wether'], 'CM' => ['male', 'sale', 'cull'],
        'DK' => ['female', null, 'female_kid'], 'GD' => ['female', null, 'growing_female'], 'RD' => ['female', 'replacement', 'replacement_female'],
        'BD' => ['female', 'breeding', 'breeding_female'], 'PD' => ['female', 'breeding', 'breeding_female'], 'DD' => ['female', 'milk', 'breeding_female'],
        'FF' => ['female', 'fattening', 'growing_female'], 'CF' => ['female', 'sale', 'cull'],
    ];

    /**
     * D-239: each code as the farm set it (Settings → Automation Rules), else the framework default:
     * code => [gender, purpose, category, label, starting age stage].
     */
    public static function picks(bool $withOff = false): array
    {
        $all = Tenant::memo('role_codes', function () {
            $saved = Tenant::current() ? \App\Models\RoleCode::all()->keyBy('code') : collect();
            $out = collect(self::PICK)->map(fn ($p, $c) => ($r = $saved[$c] ?? null)
                ? [$p[0], $r->purpose, $r->animal_class, $r->label, $r->stage_code, $r->active]
                : [$p[0], $p[1], $p[2], self::CODES[$c][0], self::defaultStage($c), true])->all();
            foreach ($saved->reject(fn ($r) => isset(self::PICK[$r->code])) as $r) { // D-240: the farm's own codes
                $out[$r->code] = [$r->sex, $r->purpose, $r->animal_class, $r->label, $r->stage_code, $r->active];
            }

            return $out;
        });

        return $withOff ? $all : array_filter($all, fn ($p) => $p[5]);
    }

    /** D-240: every code with its label and gender, the framework's and the farm's own (for filters and the dashboard). */
    public static function allCodes(): array
    {
        $custom = collect(self::picks(true))->reject(fn ($p, $c) => isset(self::CODES[$c]))->map(fn ($p) => [$p[3], $p[0], 'Farm code', true])->all();

        return self::CODES + $custom;
    }

    /** @return array<string, array{value: string, label: string, sex: string, group: string}> options for the Gender field */
    public static function pickOptions(): array
    {
        return collect(self::picks())->map(fn ($p, $c) => ['value' => $c, 'label' => "{$c} · {$p[3]}", 'name' => $p[3], 'sex' => $p[0], 'group' => $p[0] === 'male' ? 'Male' : 'Female', 'purpose' => $p[1], 'class' => $p[2], 'stage' => $p[4]])->all();
    }

    /** D-237 / D-239: the age a code means when the age is not known yet (the farm's setting, else the default). */
    public static function stageFor(string $code): ?string
    {
        return self::picks()[$code][4] ?? self::defaultStage($code);
    }

    /** kids → first kid stage after newborn, growers → GRW, the rest → first adult stage. */
    private static function defaultStage(string $code): ?string
    {
        $group = match ($code) { 'BK', 'DK' => 'kid', 'GB', 'GD', 'RB', 'RD', 'FM', 'FF', 'WTH' => 'grower', default => 'adult' };
        $stages = AgeStages::stages()->where('age_group', $group)->values();

        return ($group === 'kid' ? ($stages->get(1) ?? $stages->first()) : $stages->first())?->code;
    }

    /** Goat category (D-046) => main role, per sex */
    public const FROM_CATEGORY_PUBLIC = self::FROM_CATEGORY + ['cull' => 'CM/CF'];

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
        if ($a->registered_role && ! isset(self::CODES[$a->registered_role])) {
            return $a->registered_role; // D-240: a farm-made code the goat was registered with
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
        if ($a->sex === 'male' && ! self::castrated($a) && (isset($proven['sires'][$a->id]) || $a->registered_role === 'PS')) {
            $codes[] = 'PS';
        }
        if ($a->sex === 'female' && (isset($proven['dams'][$a->id]) || $a->registered_role === 'PD')) {
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
        return collect($codes)->mapWithKeys(fn ($c) => [$c => self::picks(true)[$c][3] ?? self::CODES[$c][0] ?? $c])->all();
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
