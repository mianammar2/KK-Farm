<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\LedgerEntry;
use App\Models\MilkDispatch;
use App\Models\MilkLot;
use App\Models\MilkLotTemp;
use App\Models\MilkRecord;
use App\Models\QualityCase;
use App\Models\QualityHold;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * D-196: journey G Milk Quality-Hold-to-Recall (V3 flow 30). Triggers (a flagged doe, a storage temperature excursion,
 * a positive residue test, a customer complaint) contain the affected milk through one quality case; the recipient
 * trace follows a lot back to its does and forward to every customer; a recalled lot's sales are refunded or credited.
 * No unapproved milk reaches sale or processing: holds block release (D-189) and only Released lots dispatch (BR-012).
 */
final class MilkTrace
{
    public static function maxStorageC(): float
    {
        return (float) (Tenant::org()?->milk_dispatch_max_c ?? 7);
    }

    public static function traceDays(): int
    {
        return (int) (Tenant::org()?->milk_trace_days ?? 3);
    }

    /** Lots whose session holds saleable milk from this doe on or after $from. */
    public static function lotsWithDoe(Animal $doe, string $from): Collection
    {
        $sessions = MilkRecord::current()->where('animal_id', $doe->id)->where('discarded', false)->whereNotNull('session_id')
            ->whereDate('milked_on', '>=', $from)->pluck('session_id')->unique();

        return MilkLot::with('dispatches.customer')->whereIn('session_id', $sessions)->orderBy('id')->get();
    }

    /**
     * A doe's milk is suspect from $from (mastitis found, or a treatment with a milk withdrawal, possibly entered late):
     * every lot still on the farm with her milk is held under one quality case; lots that already left are listed for
     * a recall decision. Returns the case, or null when none of her milk went into a lot.
     */
    public static function doeFlagged(Animal $doe, string $why, string $from, User $by): ?QualityCase
    {
        $lots = self::lotsWithDoe($doe, $from)->reject(fn (MilkLot $l) => in_array($l->status, ['rejected', 'recalled'], true) || Quality::milkLotHold($l->id));
        if ($lots->isEmpty()) {
            return null;
        }
        $onFarm = $lots->filter(fn (MilkLot $l) => in_array($l->status, ['pending_qc', 'stored', 'released', 'hold'], true) && $l->remaining() > 0.001);
        $gone = $lots->filter(fn (MilkLot $l) => $l->dispatches->isNotEmpty());
        $goneText = $gone->map(fn (MilkLot $l) => "{$l->code} → ".$l->dispatches->map(fn ($d) => $d->kind === 'processing' ? 'Processing' : ($d->customer?->name ?? $d->buyer ?? 'buyer'))->unique()->implode(', '))->implode('; ');

        return DB::transaction(function () use ($doe, $why, $from, $by, $onFarm, $gone, $goneText) {
            $case = QualityCases::open([
                'source' => 'milk', 'subject' => 'animal', 'animal_id' => $doe->id, 'severity' => $gone->isNotEmpty() ? 'critical' : 'high', 'immediate_risk' => $onFarm->isNotEmpty(),
                'title' => "Milk from {$doe->farm_number} suspect: {$why}",
                'description' => "Milk from {$doe->farm_number} since ".Carbon::parse($from)->format('d/m/Y')." may be unsafe. Held: ".($onFarm->pluck('code')->implode(', ') ?: 'none').'. Already dispatched: '.($goneText ?: 'none').'.',
            ], $by, $onFarm);
            if ($gone->isNotEmpty()) {
                QualityCases::task("Decide milk recall ({$case->label()}): {$doe->farm_number}", 'critical', 'Management', "Lots with milk from {$doe->farm_number} have left the farm: {$goneText}. Recall them from the quality case if the vet confirms the risk.", $case);
            }

            return $case;
        });
    }

    /** A storage temperature reading. Above the farm's maximum, a lot still on the farm is held under a quality case. */
    public static function temperature(MilkLot $lot, float $tempC, User $by): ?QualityCase
    {
        $max = self::maxStorageC();
        $excursion = $tempC > $max;
        MilkLotTemp::create(['lot_id' => $lot->id, 'temp_c' => $tempC, 'excursion' => $excursion, 'recorded_by' => $by->name]);
        if (! $excursion || ! in_array($lot->status, ['pending_qc', 'stored', 'released'], true)) {
            return null;
        }

        return QualityCases::open([
            'source' => 'milk', 'subject' => 'milk_lot', 'milk_lot_id' => $lot->id, 'severity' => 'high', 'immediate_risk' => true, 'containment' => 'hold',
            'title' => "Temperature excursion: {$lot->code} at {$tempC} °C", 'description' => "Stored milk read {$tempC} °C; the limit is {$max} °C. Retest before release, or discard.",
        ], $by);
    }

    /** A positive residue test: the lot is already rejected; the case asks to find which doe was treated. */
    public static function residuePositive(MilkLot $lot, User $by): QualityCase
    {
        $does = MilkRecord::current()->where('session_id', $lot->session_id)->where('discarded', false)->with('animal')->get()->map(fn ($r) => $r->animal?->farm_number)->filter()->unique()->implode(', ');

        return QualityCases::open([
            'source' => 'milk', 'subject' => 'milk_lot', 'milk_lot_id' => $lot->id, 'severity' => 'high', 'immediate_risk' => false,
            'title' => "Antibiotic residue in {$lot->code}", 'description' => "The residue test was positive; the lot is rejected. Find the source: does in this lot: ".($does ?: 'unknown').'. Check treatments and withdrawal dates.',
        ], $by);
    }

    /** A customer complaint about milk sold: a case on its lot; the quality lead may recall at once. */
    public static function complaint(MilkDispatch $d, string $details, string $severity, bool $recall, User $by): QualityCase
    {
        $lot = $d->lot;
        $buyer = $d->customer?->name ?? $d->buyer ?? 'the buyer';
        $containment = $recall ? 'recall' : (in_array($lot->status, ['stored', 'released'], true) ? 'hold' : null);

        return QualityCases::open([
            'source' => 'complaint', 'subject' => 'milk_lot', 'milk_lot_id' => $lot->id, 'severity' => $severity, 'immediate_risk' => $containment !== null, 'containment' => $containment,
            'title' => "Complaint from {$buyer}: {$lot->code}", 'description' => "{$d->invoice_no} · {$d->litres} L on ".$d->dispatched_on->format('d/m/Y').". {$details}",
        ], $by);
    }

    /**
     * Settle a sale of a recalled lot: refund (the customer had paid) or credit (still owed). The whole amount reverses
     * the sale in the books; a part refund is recorded as a Milk Refunds expense. Returned litres are noted.
     */
    public static function settle(MilkDispatch $d, string $kind, ?float $amount, ?float $returnedL, User $by): void
    {
        $lot = $d->lot;
        if ($lot->status !== 'recalled') {
            throw ValidationException::withMessages(['kind' => 'Only sales of a recalled lot are refunded or credited here.']);
        }
        if ($d->adjusted_at || ! $d->sale_id) {
            throw ValidationException::withMessages(['kind' => 'This sale is already settled, or was not a sale.']);
        }
        $full = round($d->litres * $d->price_per_litre_minor / 100, 2);
        $amount = $amount === null ? $full : round($amount, 2);
        if ($amount <= 0 || $amount > $full + 0.001) {
            throw ValidationException::withMessages(['amount' => "Between PKR 1 and the sale's PKR ".number_format($full, 2).'.']);
        }
        if ($returnedL !== null && $returnedL > $d->litres + 0.001) {
            throw ValidationException::withMessages(['returned_l' => "No more than the {$d->litres} L sold."]);
        }
        $entry = LedgerEntry::find($d->sale?->ledger_entry_id);
        $unpaid = ! $d->collected_at;
        if ($kind === 'refund' && $unpaid) {
            throw ValidationException::withMessages(['kind' => 'The customer has not paid yet: give a credit instead.']);
        }
        if ($kind === 'credit' && ! $unpaid) {
            throw ValidationException::withMessages(['kind' => 'The customer has paid: give a refund.']);
        }
        if ($kind === 'credit' && abs($amount - $full) > 0.001) {
            throw ValidationException::withMessages(['amount' => 'A credit cancels the whole unpaid invoice.']);
        }
        DB::transaction(function () use ($d, $kind, $amount, $full, $returnedL, $by, $entry) {
            if ($entry && abs($amount - $full) < 0.001) {
                LedgerEntry::create([
                    'direction' => 'out', 'category' => $entry->category, 'amount' => $entry->amount, 'occurred_on' => now(), 'counterparty' => $entry->counterparty,
                    'description' => "Milk recall {$d->lot->code}: ".($kind === 'credit' ? 'credit' : 'refund')." of {$d->invoice_no}", 'reverses_id' => $entry->id,
                    'recorded_by' => $by->name, 'account_id' => $entry->account_id, 'payment_status' => 'paid',
                ]);
            } else {
                LedgerEntry::create(['direction' => 'out', 'category' => 'Milk Refunds', 'amount' => $amount, 'occurred_on' => now(), 'counterparty' => $d->customer?->name ?? $d->buyer,
                    'description' => "Milk recall {$d->lot->code}: part refund of {$d->invoice_no}", 'recorded_by' => $by->name, 'payment_status' => 'paid']);
            }
            $d->update(['adjustment' => $kind, 'adjustment_minor' => (int) round($amount * 100), 'returned_l' => $returnedL, 'adjusted_at' => now(), 'adjusted_by' => $by->name,
                'collected_at' => $kind === 'credit' ? now() : $d->collected_at]);
            Farm::log($by, "Milk recall {$d->lot->code}: ".($kind === 'credit' ? 'credited' : 'refunded')." PKR ".number_format($amount, 2)." on {$d->invoice_no}", '/dairy?tab=trace');
        });
    }

    /** One lot back to its does and forward to every customer, with QA, temperatures, holds and settlements. */
    public static function trace(MilkLot $lot): array
    {
        $lot->load(['session', 'dispatches.customer', 'dispatches.order']);
        $records = MilkRecord::current()->where('session_id', $lot->session_id)->with('animal')->get();
        $holds = QualityHold::where('milk_lot_id', $lot->id)->with('qualityCase')->orderBy('placed_at')->get();

        return [
            'code' => $lot->code, 'status' => MilkLot::STATUS[$lot->status] ?? $lot->status, 'litres' => (float) $lot->litres, 'remaining' => $lot->remaining(), 'reason' => $lot->hold_reason,
            'session' => $lot->session ? ['on' => $lot->session->milked_on->format('d/m/Y'), 'session' => $lot->session->session, 'by' => $lot->session->closed_by, 'operator' => $lot->session->operator ?? null] : null,
            'does' => $records->map(fn ($r) => ['id' => $r->animal_id, 'number' => $r->animal?->farm_number, 'litres' => (float) $r->litres, 'held' => (bool) $r->discarded, 'reason' => $r->discard_reason])->values(),
            'qa' => ['by' => $lot->tested_by, 'at' => $lot->tested_at?->format('d/m/Y g:i A'), 'residue' => $lot->residue, 'tank' => $lot->tank, 'temp' => $lot->storage_temp_c !== null ? (float) $lot->storage_temp_c : null,
                'releasedBy' => $lot->released_by, 'releasedAt' => $lot->released_at?->format('d/m/Y g:i A')],
            'temps' => MilkLotTemp::where('lot_id', $lot->id)->orderByDesc('id')->limit(10)->get()->map(fn ($t) => ['at' => $t->created_at->format('d/m g:i A'), 'c' => $t->temp_c, 'excursion' => $t->excursion, 'by' => $t->recorded_by]),
            'dispatches' => $lot->dispatches->map(fn (MilkDispatch $d) => [
                'id' => $d->id, 'on' => $d->dispatched_on->format('d/m/Y'), 'kind' => $d->kind, 'to' => $d->kind === 'processing' ? 'Processing' : ($d->customer?->name ?? $d->buyer ?? '—'),
                'phone' => $d->customer?->phone, 'litres' => (float) $d->litres, 'invoice' => $d->invoice_no, 'order' => $d->order?->order_no, 'amount' => $d->price_per_litre_minor ? round($d->litres * $d->price_per_litre_minor / 100, 2) : null,
                'paid' => (bool) $d->collected_at, 'adjustment' => $d->adjustment, 'adjusted' => $d->adjustment_minor !== null ? $d->adjustment_minor / 100 : null, 'returned' => $d->returned_l !== null ? (float) $d->returned_l : null,
            ])->values(),
            'holds' => $holds->map(fn ($h) => ['kind' => $h->kind, 'case' => $h->qualityCase?->label(), 'caseId' => $h->case_id, 'title' => $h->qualityCase?->title, 'placed' => $h->placed_at?->format('d/m/Y'), 'released' => $h->released_at?->format('d/m/Y')])->values(),
            'recalled' => $lot->status === 'recalled',
        ];
    }

    /** KPIs of flow 30 over the last $days days. */
    public static function kpis(int $days = 30): array
    {
        $since = now()->subDays($days);
        $rejected = (float) MilkLot::where('status', 'rejected')->where('updated_at', '>=', $since)->sum('litres');
        $holds = QualityHold::whereNotNull('milk_lot_id')->where('placed_at', '>=', $since)->with('milkLot')->get();
        $hours = $holds->map(fn ($h) => $h->milkLot ? $h->milkLot->created_at->diffInMinutes($h->placed_at) / 60 : null)->filter(fn ($x) => $x !== null);
        $milkCases = QualityCase::where('source', 'milk')->orWhere(fn ($q) => $q->where('source', 'complaint')->whereNotNull('milk_lot_id'));
        $sold = MilkDispatch::where('dispatched_on', '>=', $since->toDateString())->get();
        $traced = $sold->filter(fn ($d) => $d->kind === 'processing' || $d->customer_id || $d->buyer);

        return [
            'rejectedL' => round($rejected, 1),
            'detectionHours' => $hours->isNotEmpty() ? round($hours->avg(), 1) : null,
            'openCases' => (clone $milkCases)->where('status', '!=', 'closed')->count(),
            'traceability' => $sold->isNotEmpty() ? (int) round($traced->count() / $sold->count() * 100) : 100,
            'refunds' => round(MilkDispatch::whereNotNull('adjusted_at')->where('adjusted_at', '>=', $since)->sum('adjustment_minor') / 100, 2),
        ];
    }
}
