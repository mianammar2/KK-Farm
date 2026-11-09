<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\FeedItem;
use App\Models\MarketRate;
use App\Models\WeightRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * D-113: the herd priced like a market. Each goat is worth its latest weight × the live-weight rate for its purpose
 * (or the general rate). Its cost is its purchase (batch rate × first weight, or the price on its record) plus the
 * feed it has needed since it arrived: each day, its weight that day × the intake % for its age × the feed price.
 * Gains per day, week and month compare today with that many days ago. All money is in minor units (paisa).
 */
class HerdValue
{
    public const PERIODS = ['day' => 1, 'week' => 7, 'month' => 30];

    /** @var Collection<int, MarketRate> */
    private Collection $rates;

    /** @var Collection<string, Collection<int, WeightRecord>> */
    private Collection $weights;

    private CarbonInterface $today;

    public function __construct()
    {
        $this->today = Feed::now()->startOfDay();
        $this->rates = MarketRate::orderBy('rate_on')->orderBy('id')->get();
    }

    /** The batch a goat came in with: its tag without the last number (MC241026-05 → MC241026). */
    public static function batchOf(Animal $goat): string
    {
        $pos = strrpos($goat->farm_number, '-');

        return $pos ? substr($goat->farm_number, 0, $pos) : $goat->farm_number;
    }

    /** The rate in force on a day for a purpose: its own rate if one was entered, else the general one. */
    public function rateOn(CarbonInterface $day, ?string $purpose): ?int
    {
        $upTo = $this->rates->filter(fn ($r) => $r->rate_on->lte($day));
        $own = $purpose ? $upTo->where('purpose', $purpose)->last() : null;

        return ($own ?? $upTo->whereNull('purpose')->last())?->rate_minor_per_kg;
    }

    private function weightOn(string $id, CarbonInterface $day): ?float
    {
        $rows = $this->weights[$id] ?? collect();
        $on = $rows->filter(fn ($w) => $w->measured_on->lte($day))->last() ?? $rows->first();

        return $on ? (float) $on->weight_kg : null;
    }

    private function arrived(Animal $goat): CarbonInterface
    {
        return ($goat->acquired_on ?? $goat->birth_date ?? $goat->created_at)->copy()->startOfDay();
    }

    public function build(): array
    {
        $org = Feed::org();
        $goats = Animal::active()->with('breed')->orderBy('farm_number')->get();
        $this->weights = WeightRecord::whereIn('animal_id', $goats->pluck('id'))->orderBy('measured_on')->orderBy('id')->get(['animal_id', 'measured_on', 'weight_kg'])->groupBy('animal_id');
        $req = Feed::perGoat($goats, $this->today->toDateString());
        $items = FeedItem::pluck('cost_per_kg_minor', 'id');
        $defaultFeed = Feed::defaultFeedId();
        $fallback = $org->feed_price_minor_per_kg ?: ($defaultFeed ? (int) $items[$defaultFeed] : null);

        $rows = $goats->map(function (Animal $g) use ($req, $items, $fallback) {
            $r = $req[$g->id];
            $first = ($this->weights[$g->id] ?? collect())->first();
            $weight = $r['weight'] ?? null;
            // Feed per day at a given weight, and the price per KG of this goat's feed.
            $fixed = $r['state'] === 'planned' && str_starts_with($r['rule'], 'Fixed') ? (float) $r['kgDay'] : null;
            $pct = $r['state'] === 'planned' && $weight ? $r['kgDay'] / $weight : 0.0;
            $lineCost = collect($r['lines'])->sum(fn ($kg, $id) => $kg * ($items[$id] ?? 0));
            $price = $r['kgDay'] > 0 && $lineCost > 0 ? $lineCost / $r['kgDay'] : $fallback;
            $kgOn = fn (CarbonInterface $d) => $fixed ?? (($this->weightOn($g->id, $d) ?? 0) * $pct);
            $feedCost = function (CarbonInterface $from, CarbonInterface $to) use ($kgOn, $price): float {
                $sum = 0.0;
                for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
                    $sum += $kgOn($d) * ($price ?? 0);
                }

                return $sum;
            };
            $arrived = $this->arrived($g);
            $buy = $g->purchase_cost_minor ?? ($g->purchase_rate_minor_per_kg && $first ? (int) round($g->purchase_rate_minor_per_kg * (float) $first->weight_kg) : null);
            $rate = $this->rateOn($this->today, $g->animal_purpose);
            $value = $weight && $rate ? $weight * $rate : null;
            $feed = $feedCost($arrived, $this->today->copy()->addDay());

            $periods = [];
            foreach (self::PERIODS as $key => $days) {
                $start = $this->today->copy()->subDays($days);
                $from = $arrived->gt($start) ? $arrived : $start;
                $then = $arrived->gt($start) ? null : (($w = $this->weightOn($g->id, $start)) && ($rt = $this->rateOn($start, $g->animal_purpose)) ? $w * $rt : null);
                $startValue = $then ?? ($arrived->gt($start) ? $buy : null);
                $periods[$key] = [
                    'kg' => $weight && ($w0 = $this->weightOn($g->id, $from)) ? $weight - $w0 : 0.0,
                    'value' => $value !== null && $startValue !== null ? $value - $startValue : null,
                    'feed' => $feedCost($from, $this->today->copy()->addDay()),
                ];
            }

            return [
                'id' => $g->id, 'number' => $g->farm_number, 'batch' => self::batchOf($g), 'purpose' => $g->animal_purpose, 'breed' => $g->breed?->name,
                'weight' => $weight, 'rate' => $rate, 'value' => $value, 'buy' => $buy, 'buyRate' => $g->purchase_rate_minor_per_kg, 'feed' => $feed,
                'feedDay' => $kgOn($this->today) * ($price ?? 0), 'feedKgDay' => $kgOn($this->today), 'priced' => $price !== null, 'periods' => $periods,
            ];
        });

        return ['rows' => $rows, 'fallback' => $fallback];
    }

    /** Rate history per purpose (null = general), one point per day: the last rate entered that day. */
    public function history(): array
    {
        return $this->rates->groupBy(fn ($r) => $r->purpose ?? 'all')->map(fn ($rs) => $rs->groupBy(fn ($r) => $r->rate_on->toDateString())
            ->map(fn ($day) => $day->last()->rate_minor_per_kg / 100)->all())->all();
    }

    public function rates(): Collection
    {
        return $this->rates;
    }

    public function today(): CarbonInterface
    {
        return $this->today;
    }
}
