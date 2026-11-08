<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-071: the health flag (register) and health status (health screens) disagreed for some goats, so a goat could
 * show "Healthy" and still be listed as needing attention. The status is the one the health screens update after
 * registration, so where the two disagree (or the flag is empty) the flag follows the status. The old flag is
 * kept in the goat's custom_fields under _previous_health_flag so it can be restored.
 */
return new class extends Migration
{
    private const FLAG_TO_STATUS = ['healthy' => 'healthy', 'monitor' => 'watch', 'recovering' => 'watch', 'sick' => 'treatment', 'quarantine' => 'treatment'];

    private const STATUS_TO_FLAG = ['healthy' => 'healthy', 'watch' => 'monitor', 'treatment' => 'sick', 'critical' => 'sick'];

    public function up(): void
    {
        foreach (DB::table('animals')->get(['id', 'health_flag', 'health_status', 'custom_fields']) as $a) {
            $status = $a->health_status ?: 'healthy';
            if ($a->health_flag && (self::FLAG_TO_STATUS[$a->health_flag] ?? null) === $status) {
                continue;
            }
            $custom = json_decode($a->custom_fields ?? '{}', true) ?: [];
            $custom['_previous_health_flag'] = $a->health_flag;
            DB::table('animals')->where('id', $a->id)->update(['health_flag' => self::STATUS_TO_FLAG[$status] ?? 'healthy', 'health_status' => $status, 'custom_fields' => json_encode($custom)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('animals')->whereRaw("custom_fields ? '_previous_health_flag'")->get(['id', 'custom_fields']) as $a) {
            $custom = json_decode($a->custom_fields, true);
            $flag = $custom['_previous_health_flag'];
            unset($custom['_previous_health_flag']);
            DB::table('animals')->where('id', $a->id)->update(['health_flag' => $flag, 'custom_fields' => json_encode($custom)]);
        }
    }
};
