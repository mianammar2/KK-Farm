<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-167: an ultrasound at a health checkup carries the KK Farms ultrasound status and fetal viability too. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_checkups', function (Blueprint $t) {
            $t->string('ultrasound_status', 3)->nullable();
            $t->string('viability', 3)->nullable();
        });
        foreach (['pregnant' => 'PCF', 'not_pregnant' => 'NPG', 'inconclusive' => 'INC'] as $old => $code) {
            DB::table('health_checkups')->where('ultrasound', $old)->update(['ultrasound_status' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('health_checkups', fn (Blueprint $t) => $t->dropColumn(['ultrasound_status', 'viability']));
    }
};
