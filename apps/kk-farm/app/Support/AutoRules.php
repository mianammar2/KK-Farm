<?php

namespace App\Support;

use App\Models\ClassificationRule;
use Illuminate\Support\Collection;

/**
 * D-234: the registry's automation rules (Settings → Automation Rules). From gender, age stage and purpose the goat's
 * category (and so its role code: BD, GB, FM …) and its starting female status are worked out. The defaults give the
 * same result as the earlier fixed rules, so no goat on the farm changes until the owner edits the table.
 */
final class AutoRules
{
    public const PURPOSES = ['none' => 'No purpose set', '*' => 'Any other purpose', 'breeding' => 'Breeding', 'replacement' => 'Replacement Breeding', 'fattening' => 'Fattening / Qurbani', 'meat' => 'Meat Sale', 'sale' => 'Sale', 'milk' => 'Milk'];

    /** stage group => sex => purpose => [category, female status] */
    private const DEFAULTS = [
        'kid' => ['male' => ['*' => ['male_kid', null]], 'female' => ['*' => ['female_kid', 'not_ready']]],
        'grower' => [
            'male' => ['*' => ['growing_male', null], 'breeding' => ['replacement_male', null], 'replacement' => ['replacement_male', null], 'fattening' => ['fattening_male', null]],
            'female' => ['*' => ['growing_female', 'not_ready'], 'breeding' => ['replacement_female', 'not_ready'], 'replacement' => ['replacement_female', 'not_ready']],
        ],
        'adult' => [
            'male' => ['*' => ['growing_male', null], 'none' => ['breeding_male', null], 'breeding' => ['breeding_male', null], 'milk' => ['breeding_male', null], 'replacement' => ['replacement_male', null], 'fattening' => ['fattening_male', null]],
            'female' => ['*' => ['growing_female', 'open'], 'none' => ['breeding_female', 'open'], 'breeding' => ['breeding_female', 'open'], 'milk' => ['breeding_female', 'open'], 'replacement' => ['replacement_female', 'not_ready']],
        ],
    ];

    /** @return Collection<int, ClassificationRule> (a farm with none yet gets the defaults for its age stages) */
    public static function rules(): Collection
    {
        return Tenant::memo('classification_rules', function () {
            if (! Tenant::current()) {
                return self::defaultRows()->map(fn ($r) => new ClassificationRule($r));
            }
            if (! ClassificationRule::exists()) {
                self::defaultRows()->each(fn ($r) => ClassificationRule::create($r));
            }

            return ClassificationRule::all();
        });
    }

    private static function defaultRows(): Collection
    {
        return AgeStages::stages()->flatMap(fn ($st) => collect(self::DEFAULTS[$st->age_group] ?? [])->flatMap(fn ($byPurpose, $sex) => collect($byPurpose)->map(fn ($v, $p) => [
            'sex' => $sex, 'stage_code' => $st->code, 'purpose' => $p, 'animal_class' => $v[0], 'female_status' => $sex === 'female' ? $v[1] : null,
        ])->values()))->values();
    }

    /** The rule for this gender, stage and purpose: the purpose's own row, else the stage's '*' row. */
    public static function for(?string $sex, ?string $stage, ?string $purpose): ?ClassificationRule
    {
        if (! in_array($sex, ['male', 'female'], true) || ! $stage) {
            return null;
        }
        $rows = self::rules()->where('sex', $sex)->where('stage_code', $stage);

        // no purpose → the 'none' row; any purpose without its own row → the '*' row
        return $rows->firstWhere('purpose', $purpose ?: 'none') ?? $rows->firstWhere('purpose', '*');
    }

    /** D-234: kid stage from age: newborn in the first stage, suckling until weaning age, then weaned. */
    public static function kidStage(?\Carbon\CarbonInterface $birth, bool $weaned = false): ?string
    {
        if (! $birth) {
            return null;
        }
        $days = (int) $birth->diffInDays(now());
        $newborn = (int) (AgeStages::stages()->get(1)?->startDays() ?? 15);
        $weanDays = (int) (Tenant::org()?->weaning_age_days ?? 84);

        return match (true) { $weaned || $days >= $weanDays => 'weaned', $days < $newborn => 'newborn', default => 'suckling' };
    }
}
