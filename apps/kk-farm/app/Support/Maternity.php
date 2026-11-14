<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\FarmLocation;
use App\Models\Kidding;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * D-163: the farm's Maternity Unit (MTU), run like a small hospital. One maternity shed holds:
 * Pre-Kidding Unit (does close to delivery), two Kidding Pens (one delivery each), the Post-Kidding Care Unit
 * (mother and kids after delivery), the Neonatal Care Unit (weak newborns) and a Maternity Isolation pen (does after
 * an abortion or early loss, kept away from mothers and newborns because some causes of abortion are infectious).
 * After any pregnancy outcome the doe moves there by herself, and her kids are born into it.
 */
final class Maternity
{
    /** pen code => [name, purpose, capacity] */
    public const PENS = [
        'PKU-01' => ['Pre-Kidding Unit', 'Does approaching delivery', 10],
        'KDP-01' => ['Kidding PEN', 'Individual delivery enclosure', 2],
        'KDP-02' => ['Kidding PEN', 'Individual delivery enclosure', 2],
        'PCU-01' => ['Post-Kidding Care Unit', 'Mothers and newborn kids after delivery', 20],
        'NCU-01' => ['Neonatal Care Unit', 'Newborn kids needing special care', 10],
        'ISO-01' => ['Maternity Isolation', 'Does after an abortion or early loss, apart from mothers and kids', 4],
    ];

    /** The maternity shed (the first MTU unit), created with its pens when the farm has none. */
    public static function shed(): Shed
    {
        $shed = Shed::where('type', 'MTU')->orderBy('id')->first();
        if (! $shed) {
            $farm = FarmLocation::default();
            $name = Shed::nextName($farm->code ?: 'KKF', 'MTU');
            $shed = Shed::create(['farm_id' => $farm->id, 'type' => 'MTU', 'name' => $name, 'code' => $name, 'status' => 'active']);
        }
        foreach (self::PENS as $code => [$label, $purpose, $capacity]) {
            Pen::firstOrCreate(['shed_id' => $shed->id, 'name' => $code], ['purpose' => $label, 'capacity' => $capacity, 'kind' => 'pen']);
        }

        return $shed;
    }

    public static function pen(string $code): Pen
    {
        return Pen::where('shed_id', self::shed()->id)->where('name', $code)->firstOrFail();
    }

    /** Where the doe goes after this outcome: isolation after a loss, post-kidding care otherwise. */
    public static function damPen(Kidding $k): Pen
    {
        return self::pen(in_array($k->outcome, Kidding::LOSSES, true) ? 'ISO-01' : 'PCU-01');
    }

    /** Where a newborn kid goes: neonatal care when born weak, with its mother otherwise. */
    public static function kidPen(bool $weak): Pen
    {
        return self::pen($weak ? 'NCU-01' : 'PCU-01');
    }

    /** Moves the doe (a movement event, so pen headcounts follow). */
    public static function admit(Kidding $k, Animal $dam, User $by): void
    {
        $to = self::damPen($k);
        try {
            Movements::transfer($dam, $to, $by, ['care' => true, 
                'moved_on' => $k->kidded_on->toDateString(),
                'reason' => in_array($k->outcome, Kidding::LOSSES, true) ? 'Maternity isolation after pregnancy loss' : 'Post-kidding care with her kids',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // D-190: a doe still in intake quarantine is not mixed into the Maternity Unit; she stays apart and the vet decides.
            Farm::event($dam, 'move', "Not moved to {$to->fullName()}: ".collect($e->errors())->flatten()->first(), $by->name);
        }
    }

    /** Moves for the hourly round: a goat that cannot be moved (e.g. still in intake quarantine, D-190) is skipped. */
    private static function tryMove(Animal $goat, Pen $to, User $by, string $reason): bool
    {
        try {
            return Movements::transfer($goat, $to, $by, ['reason' => $reason, 'care' => true]) !== null;
        } catch (\Illuminate\Validation\ValidationException) {
            return false;
        }
    }

    /**
     * D-164: the hourly maternity round, for the current organization:
     * - a pregnant doe whose expected kidding is inside the alert window moves to the Pre-Kidding Unit;
     * - a doe under monitoring after a delivery or loss, and her kids, are moved into the right maternity pen if they
     *   are not in the unit yet (outcomes recorded before D-163, or moved out by mistake).
     * Goats already anywhere in the Maternity Unit are left where staff put them (e.g. a Kidding Pen).
     *
     * @return array{preKidding: list<string>, afterOutcome: list<string>}
     */
    public static function sweep(): array
    {
        $shed = self::shed();
        $inUnit = Pen::where('shed_id', $shed->id)->pluck('id')->all();
        $system = new User(['name' => 'System (maternity round)']);
        $days = (int) (\App\Models\Organization::whereKey(Tenant::current())->value('kidding_alert_days') ?: 10);
        $moved = ['preKidding' => [], 'afterOutcome' => []];
        $quarantined = Intake::quarantinedIds(); // D-190: goats in intake quarantine stay apart until the vet clears them

        $near = \App\Models\Mating::with('dam')->where('status', 'pregnant')->whereNotNull('expected_kidding_on')
            ->whereDate('expected_kidding_on', '<=', now()->addDays($days)->toDateString())->get();
        foreach ($near as $m) {
            $dam = $m->dam;
            if ($dam && $dam->status === 'active' && ! in_array((int) $dam->pen_id, $inUnit, true) && ! in_array($dam->id, $quarantined, true)) {
                if (self::tryMove($dam, self::pen('PKU-01'), $system, 'Near kidding (expected '.$m->expected_kidding_on->format('d/m/Y').'): Pre-Kidding Unit')) {
                    $moved['preKidding'][] = $dam->farm_number;
                }
            }
        }

        $recent = Kidding::with(['dam', 'kids'])->whereDate('kidded_on', '>=', now()->subDays(Kidding::MONITOR_DAYS)->toDateString())->get();
        foreach ($recent as $k) {
            $dam = $k->dam;
            if ($dam && $dam->status === 'active' && $dam->isMonitored() && ! in_array((int) $dam->pen_id, $inUnit, true) && ! in_array($dam->id, $quarantined, true)) {
                self::admit($k, $dam, $system);
                $moved['afterOutcome'][] = $dam->farm_number;
            }
            foreach ($k->kids as $kid) {
                if ($kid->status === 'active' && ! in_array((int) $kid->pen_id, $inUnit, true)) {
                    if (self::tryMove($kid, self::kidPen($kid->birth_outcome === 'BAW'), $system, 'Newborn: with its mother in the Maternity Unit')) {
                        $moved['afterOutcome'][] = $kid->farm_number;
                    }
                }
            }
        }

        return $moved;
    }

    /** Creates the maternity unit for every organization (used by the migration). */
    public static function seedAll(): void
    {
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            Tenant::enter($orgId);
            try {
                self::shed();
            } finally {
                Tenant::leave();
            }
        }
    }
}
