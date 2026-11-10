<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-161: KK Farms pregnancy outcome classification. A kidding row is now any pregnancy outcome event (also an
 * abortion or early loss), with outcome, delivery method, kid counts by sex, mother status after delivery and the
 * vet follow-up. Each registered kid keeps its birth outcome (live born / born alive weak).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiddings', function (Blueprint $t) {
            $t->string('outcome', 3)->nullable();          // NKD AKD CSD PKD ABT EEL STB MBO UNK
            $t->string('delivery_method', 3)->nullable();  // NRM AST CSE NAP
            $t->boolean('premature')->default(false);
            $t->unsignedTinyInteger('live_male')->nullable();
            $t->unsignedTinyInteger('live_female')->nullable();
            $t->unsignedTinyInteger('still_male')->nullable();
            $t->unsignedTinyInteger('still_female')->nullable();
            $t->unsignedTinyInteger('weak')->default(0);
            $t->string('mother_status', 3)->nullable();    // HNR OBS LCT RTP PKC PAR VTR DED
            $t->unsignedSmallInteger('gestation_days')->nullable();
            $t->string('complications', 500)->nullable();
            $t->string('abortion_cause', 255)->nullable();
            $t->string('diagnosis', 500)->nullable();
            $t->string('vet_officer', 80)->nullable();
            $t->date('follow_up_on')->nullable();
        });
        Schema::table('animals', fn (Blueprint $t) => $t->string('birth_outcome', 3)->nullable()); // LVB BAW

        // Earlier kiddings: outcome and method from what was recorded.
        foreach (DB::table('kiddings')->get() as $k) {
            $assisted = ($k->assisted ?? 'no') !== 'no';
            $outcome = $k->born_alive > 0 && $k->stillborn > 0 ? 'MBO' : ($k->born_alive === 0 && $k->stillborn > 0 ? 'STB' : ($assisted ? 'AKD' : 'NKD'));
            $mated = $k->mating_id ? DB::table('matings')->where('id', $k->mating_id)->value('mated_on') : null;
            DB::table('kiddings')->where('id', $k->id)->update([
                'outcome' => $outcome, 'delivery_method' => $assisted ? 'AST' : 'NRM',
                'gestation_days' => $mated ? (int) round((strtotime($k->kidded_on) - strtotime($mated)) / 86400) : null,
            ]);
        }
        DB::table('animals')->whereNotNull('birth_kidding_id')->update(['birth_outcome' => 'LVB']);
    }

    public function down(): void
    {
        Schema::table('animals', fn (Blueprint $t) => $t->dropColumn('birth_outcome'));
        Schema::table('kiddings', fn (Blueprint $t) => $t->dropColumn(['outcome', 'delivery_method', 'premature', 'live_male', 'live_female', 'still_male', 'still_female', 'weak',
            'mother_status', 'gestation_days', 'complications', 'abortion_cause', 'diagnosis', 'vet_officer', 'follow_up_on']));
    }
};
