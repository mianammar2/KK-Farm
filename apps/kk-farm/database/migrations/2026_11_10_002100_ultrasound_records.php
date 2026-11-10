<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-166: pregnancy checks become KK Farms ultrasound records: ultrasound status, fetal count, viability, day and action. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pregnancy_checks', function (Blueprint $t) {
            $t->string('ultrasound_status', 3)->nullable(); // PCF NPG SPG INC VPG MPG FHN SFL CPL HYD ABN
            $t->string('viability', 3)->nullable();         // HBD HBN NAS
            $t->unsignedSmallInteger('pregnancy_day')->nullable();
            $t->string('action', 120)->nullable();
        });
        $map = ['pregnant' => 'PCF', 'not_pregnant' => 'NPG', 'inconclusive' => 'INC', 'pregnancy_loss' => 'CPL'];
        foreach (DB::table('pregnancy_checks')->get() as $c) {
            $mated = DB::table('matings')->where('id', $c->mating_id)->value('mated_on');
            DB::table('pregnancy_checks')->where('id', $c->id)->update([
                'ultrasound_status' => $map[$c->result] ?? null,
                'pregnancy_day' => $mated ? (int) round((strtotime($c->checked_on) - strtotime($mated)) / 86400) : null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('pregnancy_checks', fn (Blueprint $t) => $t->dropColumn(['ultrasound_status', 'viability', 'pregnancy_day', 'action']));
    }
};
