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
    /** D-197: purposes that need an approved meat selection before a goat can be finished for meat. */
    public const BREEDING_STOCK = ['breeding', 'replacement', 'milk'];

    /** @return array<string, mixed> one goat's numbers in its batch */
    public static function member(FatteningBatchAnimal $m, FatteningBatch $b, $req, $weights, $prices, ?int $fallbackPrice, array $extra = []): array
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
        // D-197: the feed actually fed and allocated to this goat (FeedCost) since it joined; the ration estimate only
        // when no feeding was recorded, and labelled so. Fixed at exit.
        $actual = $extra['feed'] ?? null;
        $feedCost = $m->feed_cost_minor ?? $actual ?? (int) round($kgDay * ($pricePerKg ?? 0) * $days);
        $feedBasis = $m->feed_basis ?? ($m->feed_cost_minor === null && $actual !== null ? 'actual' : 'estimate');
        $healthCost = $m->health_cost_minor ?? (int) ($extra['health'] ?? 0);
        $totalCost = (int) ($m->entry_cost_minor ?? 0) + $feedCost + $healthCost;
        $revenue = $m->status === 'sold' ? ($extra['revenue'] ?? null) : null;
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
            'feedCost' => $feedCost, 'feedBasis' => $feedBasis, 'healthCost' => $healthCost, 'entryCost' => $m->entry_cost_minor, 'totalCost' => $totalCost,
            'revenue' => $revenue, 'profit' => $revenue !== null ? $revenue - $totalCost : null,
            'costPerKgGain' => $gain && $gain > 0 ? (int) round(($feedCost + $healthCost) / $gain) : null,
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
        $extra = self::extras($b);
        $members = $b->members->filter(fn ($m) => $m->animal)->map(fn ($m) => self::member($m, $b, $req, $weights, $prices, $fallback, $extra[$m->id] ?? []))->values();
        $live = $members->whereIn('status', ['finishing', 'ready', 'reserved']);
        $sold = $members->where('status', 'sold');
        $revenue = (int) $sold->sum('revenue');
        $cost = (int) $sold->sum('totalCost');
        $gainKg = $members->sum(fn ($m) => max(0, (float) ($m['gain'] ?? 0)));
        $feed = (int) $members->sum('feedCost');
        $health = (int) $members->sum('healthCost');

        return [
            'id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'pen' => $b->pen?->fullName(), 'penId' => $b->pen_id, 'ration' => $b->ration ? ($b->ration->group?->name ?? 'Ration').' · from '.$b->ration->effective_from?->format('j M Y') : null, 'rationId' => $b->ration_plan_id,
            'started' => $b->started_on->format('j M Y'), 'daysOnFeed' => (int) $b->started_on->diffInDays(now()), 'targetWeight' => $b->target_weight_kg, 'targetDays' => $b->target_days,
            'targetDate' => $b->started_on->copy()->addDays($b->target_days)->format('j M Y'), 'status' => $b->status, 'notes' => $b->notes,
            'headcount' => $live->count(), 'atTarget' => $live->where('atTarget', true)->count(), 'exceptions' => $live->whereNotNull('exception')->count(),
            'adg' => $live->whereNotNull('adg')->count() ? (int) round($live->whereNotNull('adg')->avg('adg')) : null,
            'feedCost' => $feed, 'healthCost' => $health, 'costPerKgGain' => $gainKg > 0 ? (int) round(($feed + $health) / $gainKg) : null,
            'feedBasis' => $members->contains('feedBasis', 'estimate') ? ($members->contains('feedBasis', 'actual') ? 'mixed' : 'estimate') : 'actual',
            'sold' => $sold->count(), 'died' => $members->where('status', 'died')->count(), 'revenue' => $revenue, 'margin' => $sold->count() ? $revenue - $cost : null,
            'returnPerHead' => $sold->count() ? (int) round(($revenue - $cost) / $sold->count()) : null,
            'avgDaysOnFeed' => $members->count() ? (int) round($members->avg('days')) : null,
            'members' => $members,
        ];
    }

    /**
     * D-197: per member, the feed actually allocated since it joined (null when no feeding was recorded), health cost
     * (treatments + lab fees since it joined) and sale revenue. Exited members keep the figures fixed at exit.
     *
     * @return array<int, array{feed: ?int, health: int, revenue: ?int}>
     */
    public static function extras(FatteningBatch $b): array
    {
        $members = $b->members;
        if ($members->isEmpty()) {
            return [];
        }
        $ids = $members->pluck('animal_id')->all();
        $treat = \App\Models\Treatment::whereIn('animal_id', $ids)->get(['animal_id', 'given_on', 'cost_minor'])->groupBy('animal_id');
        $lab = \App\Models\HealthCase::whereIn('animal_id', $ids)->whereNotNull('lab_cost_minor')->get(['animal_id', 'onset_on', 'lab_cost_minor'])->groupBy('animal_id');
        $sales = \App\Models\Sale::whereIn('id', $members->pluck('sale_id')->filter())->pluck('gross_minor', 'id');
        $feedByDate = [];
        $out = [];
        foreach ($members as $m) {
            $from = $m->joined_on->copy()->startOfDay();
            $to = ($m->left_on ?? Feed::now())->copy()->startOfDay();
            $feed = null;
            if ($m->feed_cost_minor === null) {
                $key = $from->toDateString().'|'.$to->toDateString();
                $feedByDate[$key] ??= \App\Models\FeedUsage::current()->whereBetween('fed_on', [$from->toDateString(), $to->toDateString()])->exists()
                    ? FeedCost::build($from, $to)['goats']->keyBy('id') : collect();
                $row = $feedByDate[$key][$m->animal_id] ?? null;
                $feed = $row ? (int) round($row['cost'] * 100) : ($feedByDate[$key]->isNotEmpty() ? 0 : null);
            }
            $since = fn ($d) => $d && Carbon::parse($d)->gte($from);
            $health = (int) ($treat[$m->animal_id] ?? collect())->filter(fn ($t) => $since($t->given_on))->sum('cost_minor')
                + (int) ($lab[$m->animal_id] ?? collect())->filter(fn ($c) => $since($c->onset_on))->sum('lab_cost_minor');
            $out[$m->id] = ['feed' => $feed, 'health' => $health, 'revenue' => $m->sale_id ? (int) ($sales[$m->sale_id] ?? 0) : null];
        }

        return $out;
    }

    /** Eligible candidates: active goats of fattening / sale / meat purpose, or growing males, not already in an open batch. */
    public static function candidates(): array
    {
        $inBatch = FatteningBatchAnimal::whereIn('status', ['finishing', 'ready', 'reserved'])->pluck('animal_id')->flip();

        return Animal::active()->with('breed')->orderBy('farm_number')->get()
            ->filter(fn (Animal $a) => ! isset($inBatch[$a->id]) && ! in_array($a->animal_purpose, self::BREEDING_STOCK, true) && (in_array($a->animal_purpose, HerdValue::TRADE_PURPOSES, true) || in_array($a->effectiveCategory(), ['fattening_male', 'growing_male', 'cull', 'wether'], true)))
            ->map(fn (Animal $a) => ['id' => $a->id, 'number' => $a->farm_number, 'breed' => $a->breed?->name, 'sex' => $a->sex, 'purpose' => $a->animal_purpose,
                'health' => $a->health_status, 'quarantine' => \App\Models\HealthCase::where('animal_id', $a->id)->where('condition', Intake::CONDITION)->whereIn('status', \App\Models\HealthCase::ACTIVE)->exists()])
            ->values()->all();
    }

    public static function latestWeight(string $animalId): ?float
    {
        $w = WeightRecord::where('animal_id', $animalId)->orderByDesc('measured_on')->orderByDesc('id')->value('weight_kg');

        return $w !== null ? (float) $w : null;
    }

    /** @return array{0: int, 1: string, 2: int, 3: string} feed cost, its basis written out, health cost, feed basis (actual | estimate) */
    public static function exitCost(FatteningBatchAnimal $m): array
    {
        $b = $m->batch;
        $data = collect(self::batch($b)['members'])->firstWhere('id', $m->id);
        $period = Carbon::parse($m->joined_on)->format('j M').' to '.now()->format('j M');
        $basis = $data['feedBasis'] === 'actual'
            ? "Feed fed in its pens, shared per head per day ({$data['days']} days on feed, {$period})"
            : "Estimate: ration {$data['kgDay']} KG/day × feed price × {$data['days']} days on feed ({$period}); no feeding recorded";

        return [(int) ($data['feedCost'] ?? 0), $basis, (int) ($data['healthCost'] ?? 0), $data['feedBasis']];
    }

    /** The goat's open finishing record, if any. */
    public static function openMember(string $animalId): ?FatteningBatchAnimal
    {
        return FatteningBatchAnimal::where('animal_id', $animalId)->whereIn('status', ['finishing', 'ready', 'reserved'])->first();
    }

    /**
     * D-197: any exit of a finishing goat (a batch dispatch, a Sale Order, a direct sale, a death) closes its batch
     * record with the costs fixed at that moment and the sale, and closes the batch when no goat is left in it.
     */
    public static function closeMember(FatteningBatchAnimal $m, string $type, Carbon $on, ?int $saleId, array $cost, string $label): void
    {
        [$feed, $basis, $health, $feedBasis] = $cost;
        $m->update(['status' => match ($type) { 'sale' => 'sold', 'death' => 'died', default => 'removed' }, 'sale_id' => $saleId, 'left_on' => $on->toDateString(),
            'exit_weight_kg' => $m->exit_weight_kg ?? self::latestWeight($m->animal_id), 'feed_cost_minor' => $feed, 'health_cost_minor' => $health, 'feed_basis' => $feedBasis,
            'cost_basis' => $basis, 'exit_reason' => $label]);
        $batch = $m->batch;
        if ($batch->status !== 'closed' && ! $batch->members()->whereIn('status', ['finishing', 'ready', 'reserved'])->exists()) {
            $batch->update(['status' => 'closed', 'closed_on' => $on->toDateString()]);
        }
    }
}
