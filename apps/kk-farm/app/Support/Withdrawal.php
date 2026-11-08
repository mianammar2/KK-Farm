<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\Treatment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * D-073 / CLAUDE.md safety rule: a goat's withdrawal is computed from its treatment events, never from a flag.
 * Meat clear = the latest meat_clear_on of all its treatments; milk clear = the latest milk_clear_on. The
 * dates are the veterinarian's; the app only adds them up and enforces them.
 */
final class Withdrawal
{
    /** @return array{meat: ?Carbon, milk: ?Carbon} clear dates still in the future (null = clear today) */
    public static function forAnimal(string $animalId): array
    {
        $today = now()->startOfDay();
        $meat = Treatment::where('animal_id', $animalId)->max('meat_clear_on');
        $milk = Treatment::where('animal_id', $animalId)->max('milk_clear_on');

        return [
            'meat' => $meat && Carbon::parse($meat)->greaterThan($today) ? Carbon::parse($meat) : null,
            'milk' => $milk && Carbon::parse($milk)->greaterThan($today) ? Carbon::parse($milk) : null,
        ];
    }

    /** @return Collection<string, array{meat: ?string, milk: ?string}> goats under withdrawal today, by id (dates as Y-m-d) */
    public static function active(): Collection
    {
        $today = now()->toDateString();

        return Treatment::query()->selectRaw('animal_id, max(meat_clear_on) as meat, max(milk_clear_on) as milk')->groupBy('animal_id')->get()
            ->filter(fn ($r) => ($r->meat && $r->meat > $today) || ($r->milk && $r->milk > $today))
            ->mapWithKeys(fn ($r) => [$r->animal_id => ['meat' => $r->meat > $today ? $r->meat : null, 'milk' => $r->milk > $today ? $r->milk : null]]);
    }

    /** For the goat page and scan result. */
    public static function summary(Animal $animal): ?array
    {
        $w = self::forAnimal($animal->id);
        if (! $w['meat'] && ! $w['milk']) {
            return null;
        }

        return ['meat' => $w['meat']?->format('j M Y'), 'milk' => $w['milk']?->format('j M Y')];
    }
}
