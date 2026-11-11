<?php

namespace App\Support;

use App\Models\Medicine;
use App\Models\MedicineMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * D-074 medicine store rules (workbook "Medicine Stock").
 *
 * - Quantity = Σ movements. Per batch: Σ movements of that batch; its expiry is the receipt's expiry date.
 * - Status, in this order: NEGATIVE (below zero), EXPIRED (an expired batch still has stock), REORDER (at or below
 *   the reorder level), EXPIRING (a batch with stock expires within 45 days), OK.
 * - Issues take the batch that expires first (FEFO) and skip expired batches.
 */
final class MedicineStock
{
    public const EXPIRING_DAYS = 45;

    public const STATUS = ['negative' => 'Below zero', 'expired' => 'Expired stock', 'reorder' => 'Reorder', 'expiring' => 'Expiring soon', 'ok' => 'OK'];

    /** @return Collection<int, float> quantity by medicine id */
    public static function quantities(): Collection
    {
        return MedicineMovement::groupBy('medicine_id')->selectRaw('medicine_id, sum(qty) as q')->pluck('q', 'medicine_id')->map(fn ($q) => round((float) $q, 2));
    }

    /** @return Collection<int, Collection<int, array{batch: string, expiry: ?Carbon, qty: float}>> batches with stock, by medicine id, earliest expiry first */
    public static function batches(?int $medicineId = null): Collection
    {
        $rows = MedicineMovement::whereNotNull('batch')->when($medicineId, fn ($q) => $q->where('medicine_id', $medicineId))
            ->groupBy('medicine_id', 'batch')->selectRaw('medicine_id, batch, sum(qty) as q, max(expiry_on) as expiry')->get();

        return $rows->filter(fn ($r) => (float) $r->q > 0.0001)
            ->map(fn ($r) => ['medicine_id' => $r->medicine_id, 'batch' => $r->batch, 'expiry' => $r->expiry ? Carbon::parse($r->expiry) : null, 'qty' => round((float) $r->q, 2)])
            ->sortBy(fn ($b) => $b['expiry']?->timestamp ?? PHP_INT_MAX)->groupBy('medicine_id')->map(fn ($g) => $g->values());
    }

    public static function status(float $qty, float $reorder, Collection $batches): string
    {
        $today = now()->startOfDay();

        return match (true) {
            $qty < 0 => 'negative',
            $batches->contains(fn ($b) => $b['expiry'] && $b['expiry']->lessThan($today)) => 'expired',
            $qty <= $reorder => 'reorder',
            $batches->contains(fn ($b) => $b['expiry'] && $b['expiry']->lessThanOrEqualTo($today->copy()->addDays(self::EXPIRING_DAYS))) => 'expiring',
            default => 'ok',
        };
    }

    /**
     * Takes $qty out of the store for a treatment, from the in-date batch that expires first, splitting across batches.
     * What in-date stock cannot cover is still recorded (without a batch), so the shortfall shows as stock below zero.
     *
     * @return float the quantity that was not covered by in-date stock (0 = fully covered)
     */
    public static function issue(Medicine $medicine, float $qty, array $attributes): float
    {
        $left = $qty;
        $today = now()->startOfDay();
        $held = Quality::heldBatches($medicine->id); // D-189: a quality hold on a batch (or the whole medicine) blocks its use
        if (in_array(null, $held, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['medicine_id' => "{$medicine->name} is on quality hold. Use another medicine or ask Quality to release it."]);
        }
        foreach (self::batches($medicine->id)[$medicine->id] ?? [] as $batch) {
            if ($left <= 0 || ($batch['expiry'] && $batch['expiry']->lessThan($today)) || in_array($batch['batch'], $held, true)) {
                continue;
            }
            $take = min($left, $batch['qty']);
            MedicineMovement::create($attributes + ['medicine_id' => $medicine->id, 'type' => 'issue', 'qty' => -$take, 'batch' => $batch['batch']]);
            $left = round($left - $take, 2);
        }
        if ($left <= 0) {
            return 0.0;
        }
        // Stock received without a batch number covers the rest first; anything beyond it is a real shortfall.
        $unbatched = max(0.0, (float) MedicineMovement::where('medicine_id', $medicine->id)->whereNull('batch')->sum('qty'));
        MedicineMovement::create($attributes + ['medicine_id' => $medicine->id, 'type' => 'issue', 'qty' => -$left, 'batch' => null]);

        return round(max(0.0, $left - $unbatched), 2);
    }
}
