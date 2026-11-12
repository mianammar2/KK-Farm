<?php

namespace App\Support;

use App\Models\AgeStage;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * D-221 / D-229: the farm's age stages (Settings → Age Groups). Each stage starts at an age in days or calendar months
 * and lasts until the next one starts, so a goat's stage follows from its birth date and moves on by itself:
 * YKD on day 0, KID on day 15, GRW on day 60, ADT at 14 calendar months, MAT at 48 calendar months.
 * Reaching MAT does not make a goat old or a cull; that is decided separately.
 */
final class AgeStages
{
    /** code => [age category, counts as, starts at, unit] */
    public const DEFAULTS = [
        'YKD' => ['Newborn Kid', 'kid', 0, 'days'],
        'KID' => ['Kid', 'kid', 15, 'days'],
        'GRW' => ['Grower', 'grower', 60, 'days'],
        'ADT' => ['Adult', 'adult', 14, 'months'],
        'MAT' => ['Mature Adult', 'adult', 48, 'months'],
    ];

    /** @return Collection<int, AgeStage> youngest first */
    public static function stages(): Collection
    {
        return Tenant::memo('age_stages', function () {
            if (! Tenant::current()) {
                $list = collect(self::DEFAULTS)->map(fn ($d, $c) => new AgeStage(['code' => $c, 'label' => $d[0], 'age_group' => $d[1], 'starts_at' => $d[2], 'starts_unit' => $d[3]]))->values();
            } else {
                if (! AgeStage::exists()) {
                    $i = 0;
                    foreach (self::DEFAULTS as $c => $d) {
                        AgeStage::create(['code' => $c, 'label' => $d[0], 'age_group' => $d[1], 'starts_at' => $d[2], 'starts_unit' => $d[3], 'sort' => ($i += 10)]);
                    }
                }
                $list = AgeStage::all();
            }
            $list = $list->sortBy(fn (AgeStage $s) => $s->startDays())->values();
            $list->each(fn (AgeStage $s, int $i) => $s->setNext($list[$i + 1] ?? null));

            return $list;
        });
    }

    /** Age in months with fractions (for estimates and sorting only; stages use calendar dates). */
    public static function months(CarbonInterface $birth, ?CarbonInterface $at = null): float
    {
        return $birth->diffInDays($at ?? now()) / 30.4375;
    }

    /** The stage a goat born on $birth is in on $at (today by default): the last stage whose start has been reached. */
    public static function forBirth(CarbonInterface|string|null $birth, ?CarbonInterface $at = null): ?AgeStage
    {
        if (! $birth) {
            return null;
        }
        $birth = Carbon::parse($birth)->startOfDay();
        $at = ($at ? Carbon::parse($at) : now())->startOfDay();
        if ($at->lt($birth)) {
            return null;
        }

        return self::stages()->filter(fn (AgeStage $s) => $s->startDate($birth)->lte($at))->last();
    }

    public static function codeFor(CarbonInterface|string|null $birth, ?CarbonInterface $at = null): ?string
    {
        return self::forBirth($birth, $at)?->code;
    }

    /**
     * D-229: the combined display code from the two separate facts, age stage and teeth: GRW-T02, ADT-T04, MAT-T08.
     * Without a teeth examination only the age code is shown (ADT); without a birth date only the teeth (T04).
     */
    public static function displayCode(?string $ageCode, ?string $teeth): ?string
    {
        return collect([$ageCode, $teeth])->filter()->implode('-') ?: null;
    }

    /**
     * After the stages change, the Kid / Grower / Adult boundaries (in whole months) follow them: Grower starts at the
     * first stage that counts as Grower, Adult at the first that counts as Adult.
     */
    public static function syncGroups(): void
    {
        $s = AgeStage::all();
        $grower = $s->where('age_group', 'grower')->map(fn ($x) => $x->startMonths())->min();
        $adult = $s->where('age_group', 'adult')->map(fn ($x) => $x->startMonths())->min();
        $org = \App\Models\Organization::find(Tenant::current());
        if ($org && $grower !== null && $adult !== null && $adult > $grower) {
            $org->update(['grower_from_months' => max(1, (int) round($grower)), 'adult_from_months' => (int) round($adult)]);
            Classification::forgetBoundaries();
        }
    }
}
