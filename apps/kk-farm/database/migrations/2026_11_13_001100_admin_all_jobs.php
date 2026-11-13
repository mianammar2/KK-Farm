<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-256: the Administrator has every Tasks job by default. The defaults changed in config/kkfarm.php; a farm that saved
 * its role ticks (Settings → Access Rights by Role) keeps them, so the Administrator is added to these three there too.
 */
return new class extends Migration
{
    private const GATES = ['health_checkup', 'report_concern', 'give_care'];

    public function up(): void
    {
        $this->change(fn (array $roles) => array_values(array_unique([...$roles, 'ceo'])));
    }

    public function down(): void
    {
        $this->change(fn (array $roles) => array_values(array_diff($roles, ['ceo'])));
    }

    private function change(callable $f): void
    {
        foreach (DB::table('organizations')->whereNotNull('role_gates')->get(['id', 'role_gates']) as $org) {
            $gates = json_decode((string) $org->role_gates, true) ?: [];
            foreach (self::GATES as $g) {
                if (array_key_exists($g, $gates)) {
                    $gates[$g] = $f((array) $gates[$g]);
                }
            }
            DB::table('organizations')->where('id', $org->id)->update(['role_gates' => json_encode($gates)]);
        }
    }
};
