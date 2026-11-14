<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\AnimalMovement;
use App\Models\LedgerEntry;
use App\Models\Pen;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * D-076: the one way a goat moves. Pen transfers and every exit write an animal_movements row, then the goat's
 * pen and lifecycle follow from it. A sale also writes the sales row and the income ledger entry, entered once.
 */
final class Movements
{
    /** Old exit reasons (goat page, register) to movement types. */
    public const REASON_TO_TYPE = ['sold' => 'sale', 'died' => 'death', 'culled' => 'culling', 'sacrificed' => 'sacrificed', 'gifted' => 'gifted', 'transferred' => 'transferred_out', 'other' => 'other'];

    public const TYPE_TO_REASON = ['sale' => 'sold', 'death' => 'died', 'culling' => 'culled', 'sacrificed' => 'sacrificed', 'gifted' => 'gifted', 'transferred_out' => 'transferred', 'other' => 'other'];

    public static function transfer(Animal $animal, Pen $to, User $by, array $extra = []): ?AnimalMovement
    {
        if ($animal->status !== 'active') {
            throw ValidationException::withMessages(['animal_id' => "{$animal->farm_number} is no longer on the farm."]);
        }
        if ($to->kind === 'open_area') {
            throw ValidationException::withMessages(['to_pen_id' => "{$to->fullName()} is an open area: send goats out to it instead. Their home PEN stays the same."]);
        }
        if ((int) $animal->pen_id === (int) $to->id) {
            return null;
        }
        // D-207: only an active pen takes goats; BR-004: a full pen takes one more only with the owner's or manager's reason.
        // Care moves (maternity, isolation, welfare, kid care) are never stopped by capacity: humane handling comes first.
        if (($to->status ?? 'active') !== 'active') {
            throw ValidationException::withMessages(['to_pen_id' => "{$to->fullName()} is ".($to->status === 'cleaning' ? 'being cleaned' : "under {$to->status}").($to->status_reason ? " ({$to->status_reason})" : '').'. Choose another PEN.']);
        }
        $override = null;
        if ($to->capacity && empty($extra['care']) && $to->animals()->active()->count() >= $to->capacity) {
            if (empty($extra['capacity_override'])) {
                throw ValidationException::withMessages(['to_pen_id' => "{$to->fullName()} is full ({$to->capacity} goats). The owner or manager can authorise one more with a reason."]);
            }
            if (! in_array($by->role, ['ceo', 'manager'], true)) {
                throw ValidationException::withMessages(['to_pen_id' => 'Only the owner or manager authorises a move into a full PEN.']);
            }
            $override = mb_substr("Over capacity ({$to->capacity}), authorised by {$by->name}: {$extra['capacity_override']}", 0, 300);
        }
        Intake::assertNoMixing($animal, $to); // D-190: no direct mixing before clearance
        Biosecurity::assertMoveAllowed($animal, $to, $by); // D-193: affected pens stay put until the vet clears them
        $on = Carbon::parse($extra['moved_on'] ?? now());

        return DB::transaction(function () use ($animal, $to, $by, $extra, $on, $override) {
            $from = $animal->pen;
            $movement = AnimalMovement::create([
                'animal_id' => $animal->id, 'moved_on' => $on, 'type' => 'transfer', 'from_pen_id' => $animal->pen_id, 'to_pen_id' => $to->id,
                'reason' => $extra['reason'] ?? null, 'authorized_by' => $extra['authorized_by'] ?? $by->name, 'notes' => $extra['notes'] ?? null, 'recorded_by' => $by->name,
                'capacity_override' => $override,
            ]);
            $animal->update(['pen_id' => $to->id]);
            Farm::event($animal, 'move', 'Moved from '.($from?->fullName() ?? 'no PEN')." to {$to->fullName()}".(! empty($extra['reason']) ? " ({$extra['reason']})" : ''), $by->name, $on);

            return $movement;
        });
    }

    /**
     * A goat leaves the farm. Meat exits are refused while the goat is under meat withdrawal (D-073).
     *
     * @param  array{moved_on?: string, destination?: ?string, reason?: ?string, authorized_by?: ?string, amount?: ?float, fee?: ?float, evidence_ref?: ?string, notes?: ?string, sale_category?: ?string}  $data
     */
    public static function exit(Animal $animal, string $type, User $by, array $data = []): AnimalMovement
    {
        if (! (AnimalMovement::TYPES[$type][1] ?? false)) {
            throw ValidationException::withMessages(['type' => 'Not an exit type.']);
        }
        if ($animal->status !== 'active') {
            throw ValidationException::withMessages(['animal_id' => "{$animal->farm_number} is no longer on the farm."]);
        }
        if (in_array($type, AnimalMovement::MEAT_EXITS, true)) {
            Quality::assertAnimalNotHeld($animal, 'type'); // D-189: held animals cannot sell
            Biosecurity::assertExitAllowed($animal); // D-193
            $meat = Withdrawal::forAnimal($animal->id)['meat'];
            if ($meat) {
                throw ValidationException::withMessages(['type' => "{$animal->farm_number} is under meat withdrawal until {$meat->format('d/m/Y')} (veterinary treatment). It cannot leave for meat before then."]);
            }
        }
        $finishing = Fattening::openMember($animal->id); // D-197
        if ($finishing && $type === 'sale' && $finishing->batch?->status === 'sale_hold') {
            throw ValidationException::withMessages(['type' => "{$animal->farm_number}'s finishing batch {$finishing->batch->code} is on Sale Hold."]);
        }
        $cost = $finishing ? Fattening::exitCost($finishing) : null; // fixed before the exit changes the goat
        $on = Carbon::parse($data['moved_on'] ?? now());
        $amount = isset($data['amount']) && $data['amount'] !== '' ? (int) round((float) $data['amount'] * 100) : null;

        return DB::transaction(function () use ($animal, $type, $by, $data, $on, $amount, $finishing, $cost) {
            $movement = AnimalMovement::create([
                'animal_id' => $animal->id, 'moved_on' => $on, 'type' => $type, 'from_pen_id' => $animal->pen_id, 'destination' => $data['destination'] ?? null,
                'reason' => $data['reason'] ?? null, 'authorized_by' => $data['authorized_by'] ?? $by->name, 'amount_minor' => $amount,
                'evidence_ref' => $data['evidence_ref'] ?? null, 'notes' => $data['notes'] ?? null, 'recorded_by' => $by->name,
            ]);
            Farm::closeOpenTag($animal, self::TYPE_TO_REASON[$type], $by);
            $animal->update([
                'status' => AnimalMovement::TYPES[$type][2], 'exit_reason' => self::TYPE_TO_REASON[$type], 'exit_date' => $on, 'pen_id' => null, 'feeding_group_id' => null,
            ]);
            $label = AnimalMovement::TYPES[$type][0];
            Farm::event($animal, 'exit', "Left the farm: {$label}".(! empty($data['destination']) ? " · {$data['destination']}" : '').($amount ? ' · PKR '.number_format($amount / 100) : ''), $by->name, $on);

            $sale = null;
            if ($type === 'sale' && $amount) {
                $sale = self::sale([
                    'sold_on' => $on->toDateString(), 'animal_id' => $animal->id, 'category' => $data['sale_category'] ?? 'live_goat', 'buyer' => $data['destination'] ?? null,
                    'gross' => $amount / 100, 'fee' => $data['fee'] ?? 0, 'receipt_ref' => $data['evidence_ref'] ?? null, 'movement_id' => $movement->id,
                    'payment_status' => $data['payment_status'] ?? 'paid', 'customer_id' => $data['customer_id'] ?? null,
                ], $by, "Sale of {$animal->farm_number}");
            }
            if ($finishing) {
                Fattening::closeMember($finishing, $type, $on, $sale?->id, $cost, $label);
            }
            if ($type === 'death') {
                Waste::carcassTask($animal); // D-204
            }

            return $movement;
        });
    }

    /** A sale row and its income ledger entry (net of fee/discount). Used for goats and for products. */
    public static function sale(array $data, User $by, ?string $description = null): Sale
    {
        $gross = (int) round((float) $data['gross'] * 100);
        $fee = (int) round((float) ($data['fee'] ?? 0) * 100);
        if ($fee > $gross) {
            throw ValidationException::withMessages(['fee' => 'The fee or discount cannot be more than the price.']);
        }

        return DB::transaction(function () use ($data, $by, $gross, $fee, $description) {
            $ledger = LedgerEntry::create([
                'direction' => 'in', 'category' => ($data['category'] ?? 'other') === 'product' ? 'Product sale' : 'Animal sale', 'amount' => ($gross - $fee) / 100, 'fee' => $fee ? $fee / 100 : null, 'money_account_id' => $data['money_account_id'] ?? null,
                'occurred_on' => $data['sold_on'], 'counterparty' => $data['buyer'] ?? null, 'description' => $description ?? ($data['item'] ?? 'Sale'),
                'animal_id' => $data['animal_id'] ?? null, 'recorded_by' => $by->name, 'payment_status' => $data['payment_status'] ?? 'paid', // D-194: credit sale → receivable
            ]);

            return Sale::create([
                'sold_on' => $data['sold_on'], 'animal_id' => $data['animal_id'] ?? null, 'category' => $data['category'] ?? 'other', 'item' => $data['item'] ?? null,
                'buyer' => $data['buyer'] ?? null, 'gross_minor' => $gross, 'fee_minor' => $fee, 'receipt_ref' => $data['receipt_ref'] ?? null, 'notes' => $data['notes'] ?? null,
                'movement_id' => $data['movement_id'] ?? null, 'ledger_entry_id' => $ledger->id, 'recorded_by' => $by->name, 'customer_id' => $data['customer_id'] ?? null, // D-178
            ]);
        });
    }
}
