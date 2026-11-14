<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\HealthCase;
use App\Models\Pen;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * D-193 (journey D key control): a pen with an active contagious case is under movement restriction until the vet clears
 * the case: no goat moves in or out and none of its goats is sold or sent for meat. Only a veterinarian moves goats of a
 * restricted pen (e.g. into isolation).
 */
final class Biosecurity
{
    /** @return array<int, string> restricted pen id => case labels */
    public static function restrictedPens(): array
    {
        return HealthCase::whereIn('status', HealthCase::ACTIVE)->whereNotNull('restricted_pen_id')->get(['case_no', 'restricted_pen_id'])
            ->groupBy('restricted_pen_id')->map(fn ($g) => $g->map->label()->implode(', '))->all();
    }

    public static function assertMoveAllowed(Animal $animal, Pen $to, User $by): void
    {
        if ($by->passes('clinical')) {
            return;
        }
        $r = self::restrictedPens();
        foreach ([(int) $animal->pen_id => 'out of', (int) $to->id => 'into'] as $pen => $dir) {
            if (isset($r[$pen])) {
                $name = Pen::find($pen)?->fullName() ?? 'this PEN';
                throw ValidationException::withMessages(['to_pen_id' => "{$name} is under movement restriction ({$r[$pen]}). No goat moves {$dir} it until the vet clears the case."]);
            }
        }
    }

    public static function assertExitAllowed(Animal $animal): void
    {
        $r = self::restrictedPens();
        if ($animal->pen_id && isset($r[(int) $animal->pen_id])) {
            throw ValidationException::withMessages(['type' => "{$animal->farm_number} is in a PEN under movement restriction ({$r[(int) $animal->pen_id]}). It cannot be sold or leave for meat until the vet clears the case."]);
        }
    }
}
