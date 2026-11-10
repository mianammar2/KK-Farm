<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-162: after a delivery or a pregnancy loss the doe is no longer "Pregnant": she is under monitoring for 3 days,
 * then lactating (delivery) or open (loss). Does whose pregnancy already ended keep the right status now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animals', function (Blueprint $t) {
            $t->date('monitor_until')->nullable();
            $t->string('monitor_reason', 3)->nullable(); // the pregnancy outcome code
        });

        // Does still marked pregnant although their latest pregnancy has ended.
        foreach (DB::table('kiddings')->orderBy('kidded_on')->get() as $k) {
            $later = DB::table('matings')->where('dam_id', $k->dam_id)->where('status', 'pregnant')->exists();
            if ($later) {
                continue; // she is pregnant again
            }
            $loss = in_array($k->outcome, ['ABT', 'EEL'], true);
            $until = date('Y-m-d', strtotime($k->kidded_on.' +3 days'));
            DB::table('animals')->where('id', $k->dam_id)->whereIn('repro_status', ['pregnant', 'expected_kidding'])->update([
                'repro_status' => $loss ? 'open' : 'lactating', 'repro_state' => $loss ? null : 'lactating',
            ]);
            if ($until >= date('Y-m-d')) {
                DB::table('animals')->where('id', $k->dam_id)->update(['monitor_until' => $until, 'monitor_reason' => $k->outcome]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('animals', fn (Blueprint $t) => $t->dropColumn(['monitor_until', 'monitor_reason']));
    }
};
