<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\FatteningBatch;
use App\Models\FatteningBatchAnimal;
use App\Models\FeedItem;
use App\Models\WeightRecord;
use Illuminate\Support\Carbon;

/**
 * D-187: figures for a finishing batch (V3 flow 18). Daily feed cost of a goat comes from its ration (KG/day × the
 * feed's price), the same basis as Herd Value; it is allocated once, at the goat's exit, with the basis written down
 * (BR-020 / BR-032). Weight or welfare exceptions: losing weight, ADG below half the target pace, BCS ≤ 2, or an open
 * health case.
 */
final class Fattening
{
    /** @return array<string, mixed> one goat's numbers in its batch */
    public static function member(FatteningBatchAnimal $m, FatteningBatch $b, $req, $weights, $prices, ?int $fallbackPrice): array
    {
        $a = $m->animal;
        $w = $weights[$a->id] ?? collect();
        $latest = $w->last();
        $current = $latest ? (float) $latest->weight_kg : $m->entry_weight_kg;
        $days = max(1, (int) $m->joined_on->diffInDays($m->left_on ?? now()));
        $gain = $current !== null && $m->entry_weight_kg !== null ? $current - $m->entry_weight_kg : null;
        $adg = $gain !== null ? (int) round($gain * 1000 / $days) : null;
        $r = $req[$a->id] ?? null;
        $kgDay = $r && $r['state'] === 'planned' ? (float) $r['kgDay'] : 0.0;
        $lineCost = $r ? collect($r['lines'])->sum(fn ($kg, $id) => $kg * ($prices[$id] ?? 0)) : 0;
        $pricePerKg = $kgDay > 0 && $lineCost > 0 ? $lineCost / $kgDay : $fallbackPrice;
        $feedCost = $m->feed_cost_minor ?? (int) round($kgDay * ($pricePerKg ?? 0) * $days);
        $targetPace = $b->target_days > 0 && $m->entry_weight_kg !== null ? ($b->target_weight_kg - $m->entry_weight_kg) * 1000 / $b->target_days : null;
        $bcs = $w->whereNotNull('body_score')->last()?->body_score;
        $sick = in_array($a->health_flag, ['sick', 'quarantine'], true);
        $exception = match (true) {
            $sick => 'Under veterinary care',
            $gain !== null && $gain < 0 => 'Losing weight',
            $bcs !== null && $bcs <= 2 => "Body condition {$bcs}",
            $adg !== null && $targetPace && $days >= 14 && $adg < $targetPace / 2 => "Slow gain ({$adg} g/day)",
            default => null,
        };
        $meat = Withdrawal::forAnimal($a->id)['meat'];

        return [
            'id' => $m->id, 'goatId' => $a->id, 'number' => $a->farm_number, 'breed' => $a->breed?->name, 'sex' => $a->sex, 'status' => $m->status,
            'joined' => $m->joined_on->format('j M'), 'days' => $days, 'entry' => $m->entry_weight_kg, 'current' => $current, 'gain' => $gain !== null ? round($gain, 1) : null,
            'adg' => $adg, 'bcs' => $bcs, 'lastWeighed' => $latest?->measured_on?->format('j M'), 'kgDay' => round($kgDay, 2),
            'feedCost' => $feedCost, 'entryCost' => $m->entry_cost_minor, 'costPerKgGain' => $gain && $gain > 0 ? (int) round(($feedCost) / $gain) : null,
            'atTarget' => $current !== null && $current >= $b->target_weight_kg, 'exception' => $exception,
            'meatHold' => $meat?->format('j M Y'), 'cleared' => $m->cleared_at !== null, 'clearedBy' => $m->cleared_by,
            'health' => $a->health_status, 'saleId' => $m->sale_id, 'exitWeight' => $m->exit_weight_kg, 'exitReason' => $m->exit_reason, 'leftOn' => $m->left_on?->format('j M Y'),
        ];
    }

    /** @return array<string, mixed> */
    public static function batch(FatteningBatch $b): array
    {
        $b->loadMissing(['members.animal.breed', 'pen.shed', 'ration.group']);
        $animals = new \Illuminate\Database\Eloquent\Collection($b->members->pluck('animal')->filter()->values()->all());
        $weights = WeightRecord::whereIn('animal_id', $animals->modelKeys())->orderBy('measured_on')->orderBy('id')->get()->groupBy('animal_id');
        $req = Feed::perGoat($animals->where('status', 'active')->values(), now()->toDateString());
        $prices = FeedItem::pluck('cost_per_kg_minor', 'id');
        $org = Feed::org();
        $defaultFeed = Feed::defaultFeedId();
        $fallback = $org->feed_price_minor_per_kg ?: ($defaultFeed ? (int) ($prices[$defaultFeed] ?? 0) : null);
        $members = $b->members->filter(fn ($m) => $m->animal)->map(fn ($m) => self::member($m, $b, $req, $weights, $prices, $fallback))->values();
        $live = $members->whereIn('status', ['finishing', 'ready', 'reserved']);
        $sold = $members->where('status', 'sold');
        $revenue = (int) \App\Models\Sale::whereIn('id', $sold->pluck('saleId')->filter())->sum('gross_minor');
        $cost = (int) $sold->sum(fn ($m) => ($m['entryCost'] ?? 0) + $m['feedCost']);
        $gainKg = $members->sum(fn ($m) => max(0, (float) ($m['gain'] ?? 0)));
        $feed = (int) $members->sum('feedCost');

        return [
            'id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'pen' => $b->pen?->fullName(), 'penId' => $b->pen_id, 'ration' => $b->ration ? ($b->ration->group?->name ?? 'Ration').' · from '.$b->ration->effective_from?->format('j M Y') : null, 'rationId' => $b->ration_plan_id,
            'started' => $b->started_on->format('j M Y'), 'daysOnFeed' => (int) $b->started_on->diffInDays(now()), 'targetWeight' => $b->target_weight_kg, 'targetDays' => $b->target_days,
            'targetDate' => $b->started_on->copy()->addDays($b->target_days)->format('j M Y'), 'status' => $b->status, 'notes' => $b->notes,
            'headcount' => $live->count(), 'atTarget' => $live->where('atTarget', true)->count(), 'exceptions' => $live->whereNotNull('exception')->count(),
            'adg' => $live->whereNotNull('adg')->count() ? (int) round($live->whereNotNull('adg')->avg('adg')) : null,
            'feedCost' => $feed, 'costPerKgGain' => $gainKg > 0 ? (int) round($feed / $gainKg) : null,
            'sold' => $sold->count(), 'died' => $members->where('status', 'died')->count(), 'revenue' => $revenue, 'margin' => $sold->count() ? $revenue - $cost : null,
            'members' => $members,
        ];
    }

    /** Eligible candidates: active goats of fattening / sale / meat purpose, or growing males, not already in an open batch. */
    public static function candidates(): array
    {
        $inBatch = FatteningBatchAnimal::whereIn('status', ['finishing', 'ready', 'reserved'])->pluck('animal_id')->flip();

        return Animal::active()->with('breed')->orderBy('farm_number')->get()
            ->filter(fn (Animal $a) => ! isset($inBatch[$a->id]) && (in_array($a->animal_purpose, HerdValue::TRADE_PURPOSES, true) || in_array($a->effectiveCategory(), ['fattening_male', 'growing_male', 'cull', 'wether'], true)))
            ->map(fn (Animal $a) => ['id' => $a->id, 'number' => $a->farm_number, 'breed' => $a->breed?->name, 'sex' => $a->sex, 'purpose' => $a->animal_purpose,
                'health' => $a->health_status, 'quarantine' => \App\Models\HealthCase::where('animal_id', $a->id)->where('condition', Intake::CONDITION)->whereIn('status', \App\Models\HealthCase::ACTIVE)->exists()])
            ->values()->all();
    }

    public static function latestWeight(string $animalId): ?float
    {
        $w = WeightRecord::where('animal_id', $animalId)->orderByDesc('measured_on')->orderByDesc('id')->value('weight_kg');

        return $w !== null ? (float) $w : null;
    }

    public static function exitCost(FatteningBatchAnimal $m): array
    {
        $b = $m->batch;
        $data = collect(self::batch($b)['members'])->firstWhere('id', $m->id);

        return [(int) ($data['feedCost'] ?? 0), "Ration {$data['kgDay']} KG/day × feed price × {$data['days']} days on feed (".Carbon::parse($m->joined_on)->format('j M').' to '.now()->format('j M').')'];
    }
}
