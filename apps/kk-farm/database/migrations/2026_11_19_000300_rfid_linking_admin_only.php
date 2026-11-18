<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-350: the owner: only the administrator links RFID tags. Organizations that saved their rights keep everything else;
 * "Assign RFID tags" becomes Administrator only. It can be ticked for other roles again in Settings → Access.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('organizations')->whereNotNull('role_gates')->get(['id', 'role_gates']) as $o) {
            $g = json_decode((string) $o->role_gates, true) ?: [];
            if (isset($g['assign_tags'])) {
                $g['assign_tags'] = ['ceo'];
                DB::table('organizations')->where('id', $o->id)->update(['role_gates' => json_encode($g)]);
            }
        }
    }

    public function down(): void
    {
        // the previous roles are not restored automatically; tick them in Settings → Access
    }
};
