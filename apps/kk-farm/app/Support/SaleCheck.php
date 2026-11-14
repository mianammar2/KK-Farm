<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\FeedUsage;
use App\Models\HealthCase;
use App\Models\RfidAssignment;
use App\Models\SaleOrderLine;
use App\Models\Treatment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * D-194 (journey E): review animal ID, health and ownership before a goat is quoted, reserved or loaded. Anything here
 * must be resolved first (withdrawal, missing RFID tag, quality hold, restricted pen, sickness or quarantine, another order,
 * already gone). And the cost basis at dispatch: purchase + feed + health.
 */
final class SaleCheck
{
    /** @return array<int, string> the blockers for this goat (empty = clear) */
    public static function blockers(Animal $a, ?int $ignoreOrderId = null): array
    {
        $out = [];
        if ($a->status !== 'active') {
            $out[] = 'Already left the farm';
        }
        if ($meat = Withdrawal::forAnimal($a->id)['meat']) {
            $out[] = 'Meat withdrawal until '.$meat->format('d/m/Y');
        }
        if (! RfidAssignment::where('animal_id', $a->id)->whereNull('valid_to')->exists()) {
            $out[] = 'No RFID tag: assign one before sale';
        }
        if ($h = Quality::animalHold($a->id)) {
            $out[] = 'Quality hold '.$h->qualityCase?->label();
        }
        if ($a->pen_id && isset(Biosecurity::restrictedPens()[(int) $a->pen_id])) {
            $out[] = 'Pen under movement restriction';
        }
        $case = HealthCase::where('animal_id', $a->id)->whereIn('status', HealthCase::ACTIVE)->first();
        if ($case) {
            $out[] = $case->condition === Intake::CONDITION ? 'In intake quarantine' : "Open health case {$case->label()}";
        }
        if ($w = WelfareCare::restriction($a->id)) {
            $out[] = "Welfare case {$w->label()} open"; // D-199
        }
        if (($m = Fattening::openMember($a->id)) && $m->batch?->status === 'sale_hold') {
            $out[] = "Finishing batch {$m->batch->code} on Sale Hold"; // D-197
        }
        $other = SaleOrderLine::with('order')->where('animal_id', $a->id)->whereIn('status', SaleOrderLine::OPEN)->when($ignoreOrderId, fn ($q) => $q->where('order_id', '!=', $ignoreOrderId))->first();
        if ($other) {
            $out[] = 'Already on order '.$other->order?->order_no;
        }

        return $out;
    }

    /**
     * Cost basis per goat in minor units: purchase cost, feed over the last 12 months (FeedCost allocation) and health
     * (treatments + lab fees). Shown with its parts; nothing is posted again (feed and medicine were expensed when bought).
     *
     * @return array<string, array{purchase: int, feed: int, health: int, total: int}>
     */
    public static function costBasis(Collection $animals): array
    {
        $ids = $animals->pluck("id")->all();
        $from = Feed::now()->subDays(365)->startOfDay();
        $feed = FeedUsage::current()->where('fed_on', '>=', $from->toDateString())->exists()
            ? FeedCost::build(Carbon::parse($from), Carbon::parse(Feed::now()->startOfDay()))['goats']->keyBy('id') : collect();
        $health = Treatment::whereIn('animal_id', $ids)->selectRaw('animal_id, sum(cost_minor) as c')->groupBy('animal_id')->pluck('c', 'animal_id');
        $lab = HealthCase::whereIn('animal_id', $ids)->selectRaw('animal_id, sum(coalesce(lab_cost_minor, 0)) as c')->groupBy('animal_id')->pluck('c', 'animal_id');
        $out = [];
        foreach ($animals as $a) {
            $p = (int) ($a->purchase_cost_minor ?? 0);
            $f = (int) round(($feed[$a->id]['cost'] ?? 0) * 100);
            $h = (int) ($health[$a->id] ?? 0) + (int) ($lab[$a->id] ?? 0);
            $out[$a->id] = ['purchase' => $p, 'feed' => $f, 'health' => $h, 'total' => $p + $f + $h];
        }

        return $out;
    }
}
