<?php

namespace App\Support;

use App\Models\Animal;
use Illuminate\Support\Collection;

/**
 * D-071: one place that decides which goats count as "pregnant", "need attention", "no pen" or each CEO category.
 * The dashboard counts with it and the Goat Registry filters with it (/goats?show=KEY), so a number on the
 * dashboard always opens exactly the goats it counted.
 */
final class HerdStats
{
    public const LABELS = [
        'total' => 'Total Goats', 'breeding_males' => 'Breeding Male Goats', 'breeding_females' => 'Breeding Female Goats',
        'pregnant' => 'Pregnant Females', 'ready' => 'Ready for Breeding', 'male_kids' => 'Male Kids', 'female_kids' => 'Female Kids',
        'growing' => 'Growing Goats', 'lactating' => 'Lactating Females', 'sick' => 'Sick Goats', 'treatment' => 'Goats Under Treatment',
        'for_sale' => 'Goats Ready for Sale',
        // Not CEO categories, but filters the dashboard links to.
        'attention' => 'Need attention', 'no_pen' => 'Not in a pen', 'no_weight' => 'No weight yet', 'age_conflict' => 'Birth date does not fit its category', 'missing' => 'Missing data',
    ];

    public const CATEGORIES = ['breeding_males', 'breeding_females', 'pregnant', 'ready', 'male_kids', 'female_kids', 'growing', 'lactating', 'sick', 'treatment', 'for_sale'];

    /** @return list<string> every key this goat belongs to */
    public static function tags(Animal $a, bool $hasWeight): array
    {
        $cat = $a->effectiveCategory();
        $female = $a->sex === 'female';
        $flag = $a->health_flag ?? 'healthy';
        $tests = [
            'total' => true,
            'breeding_males' => $cat === 'breeding_male',
            'breeding_females' => $cat === 'breeding_female',
            'pregnant' => $female && (in_array($a->repro_status, ['pregnant', 'expected_kidding'], true) || $a->repro_state === 'pregnant'),
            'ready' => $female && $a->repro_status === 'ready',
            'male_kids' => $cat === 'male_kid',
            'female_kids' => $cat === 'female_kid',
            'growing' => in_array($cat, ['growing_male', 'growing_female', 'replacement_male', 'replacement_female'], true),
            'lactating' => $female && (in_array($a->repro_status, ['lactating', 'nursing'], true) || $a->repro_state === 'lactating'),
            'sick' => $flag === 'sick',
            'treatment' => in_array($flag, ['sick', 'quarantine'], true),
            'for_sale' => in_array($a->animal_purpose, ['sale', 'meat'], true) || in_array($cat, ['fattening_male', 'cull'], true) || $a->kid_stage === 'sale_selected',
            'attention' => $flag !== 'healthy',
            'no_pen' => $a->pen_id === null,
            'no_weight' => ! $hasWeight,
            // D-080: e.g. a Breeding Male whose birth date makes it a few days old: the date is probably the purchase date.
            'age_conflict' => $a->effectiveAge() === 'kid' && in_array($a->animal_class, \App\Support\Feed::ADULT_CATEGORIES, true),
        ];
        $tests['missing'] = $tests['no_pen'] || $tests['no_weight'] || $tests['age_conflict'];

        return array_keys(array_filter($tests));
    }

    /** @return Collection<string, list<string>> tags by goat id, for goats on the farm */
    public static function tagsFor(Collection $goats): Collection
    {
        $weighed = \Illuminate\Support\Facades\DB::table('weight_records')->distinct()->pluck('animal_id')->flip();

        return $goats->mapWithKeys(fn (Animal $a) => [$a->id => self::tags($a, $weighed->has($a->id))]);
    }
}
