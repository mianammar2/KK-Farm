<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\FeedItem;
use App\Models\QualityHold;
use Illuminate\Validation\ValidationException;

/**
 * D-189: the App 21 key control "held food / animal products cannot sell". An active hold, isolation or recall
 * (Quality & Compliance) blocks: a goat's sale or meat exit, a milk lot's release and dispatch, a feed in daily feeding
 * and TMR mixing (BR-016), and a medicine batch in treatment issues (BR-025).
 */
final class Quality
{
    public static function animalHold(string $animalId): ?QualityHold
    {
        return QualityHold::active()->where('animal_id', $animalId)->with('qualityCase')->first();
    }

    public static function milkLotHold(int $lotId): ?QualityHold
    {
        return QualityHold::active()->where('milk_lot_id', $lotId)->with('qualityCase')->first();
    }

    /** @param  array<int, int>  $feedItemIds */
    public static function assertFeedNotHeld(array $feedItemIds, string $field = 'lines'): void
    {
        $held = QualityHold::active()->whereIn('feed_item_id', $feedItemIds)->with('qualityCase')->get();
        if ($held->isNotEmpty()) {
            $names = FeedItem::whereIn('id', $held->pluck('feed_item_id'))->pluck('name')->implode(', ');
            throw ValidationException::withMessages([$field => "{$names} is on quality hold (".$held->map(fn ($h) => $h->qualityCase?->label())->unique()->implode(', ').'). It cannot be fed or mixed until Quality releases it.']);
        }
    }

    /** Held batches of a medicine; null in the list means the whole medicine is held. @return array<int, ?string> */
    public static function heldBatches(int $medicineId): array
    {
        return QualityHold::active()->where('medicine_id', $medicineId)->pluck('batch')->all();
    }

    public static function assertAnimalNotHeld(Animal $animal, string $field = 'animal_id'): void
    {
        if ($hold = self::animalHold($animal->id)) {
            throw ValidationException::withMessages([$field => "{$animal->farm_number} is under a quality ".strtolower(QualityHold::KINDS[$hold->kind]).' ('.$hold->qualityCase?->label().'). It cannot be sold or leave for meat until Quality releases it.']);
        }
    }
}
