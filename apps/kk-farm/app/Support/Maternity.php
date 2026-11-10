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
        'KDP-01' => ['Kidding Pen', 'Individual delivery enclosure', 2],
        'KDP-02' => ['Kidding Pen', 'Individual delivery enclosure', 2],
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
        Movements::transfer($dam, $to, $by, [
            'moved_on' => $k->kidded_on->toDateString(),
            'reason' => in_array($k->outcome, Kidding::LOSSES, true) ? 'Maternity isolation after pregnancy loss' : 'Post-kidding care with her kids',
        ]);
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
