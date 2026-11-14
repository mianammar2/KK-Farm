<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** D-269: culling from breeding is the vet's by default; a farm that saved its role ticks loses the Administrator there too. */
return new class extends Migration
{
    public function up(): void
    {
        $this->change(fn (array $roles) => array_values(array_diff($roles, ['ceo'])));
    }

    public function down(): void
    {
        $this->change(fn (array $roles) => array_values(array_unique([...$roles, 'ceo'])));
    }

    private function change(callable $f): void
    {
        foreach (DB::table('organizations')->whereNotNull('role_gates')->get(['id', 'role_gates']) as $org) {
            $gates = json_decode((string) $org->role_gates, true) ?: [];
            if (array_key_exists('cull_breeding', $gates)) {
                $gates['cull_breeding'] = $f((array) $gates['cull_breeding']);
                DB::table('organizations')->where('id', $org->id)->update(['role_gates' => json_encode($gates)]);
            }
        }
    }
};
