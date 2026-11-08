<?php

use App\Models\Pen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-057: pens are stored as P01, P02 … inside their shed and shown as SHED-P01 (e.g. KKF-BDU-01-P01).
 * Existing pens keep their number ("Pen 01" → P01, "Pen 05" → P05); pens without one ("Pen Main", "Pen B")
 * get the next free number in their shed, in id order. The previous name is kept in previous_code for rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pens', fn (Blueprint $t) => $t->string('previous_code')->nullable());

        foreach (DB::table('pens')->orderBy('shed_id')->orderBy('id')->get()->groupBy('shed_id') as $pens) {
            $used = [];
            $later = [];
            foreach ($pens as $pen) {
                $code = Pen::standardName($pen->name);
                if ($code !== '' && ! in_array($code, $used, true)) {
                    $used[] = $code;
                    $this->rename($pen, $code);
                } else {
                    $later[] = $pen;
                }
            }
            foreach ($later as $pen) {
                for ($n = 1; in_array($code = sprintf('P%02d', $n), $used, true); $n++);
                $used[] = $code;
                $this->rename($pen, $code);
            }
        }
    }

    private function rename(object $pen, string $code): void
    {
        if ($code !== $pen->name) {
            DB::table('pens')->where('id', $pen->id)->update(['name' => $code, 'previous_code' => $pen->name]);
        }
    }

    public function down(): void
    {
        DB::table('pens')->whereNotNull('previous_code')->update(['name' => DB::raw('previous_code')]);
        Schema::table('pens', fn (Blueprint $t) => $t->dropColumn('previous_code'));
    }
};
