<?php

use App\Models\Pen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-055: existing pens renamed to the "Pen …" pattern ("Main pen" → "Pen Main", "p01" → "Pen 01", "02" → "Pen 02").
 * The old name is kept in previous_name so the rename can be rolled back. A clash inside a shed gets " (2)", " (3)".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pens', fn (Blueprint $t) => $t->string('previous_name')->nullable());

        foreach (DB::table('pens')->orderBy('id')->get() as $pen) {
            $name = Pen::standardName($pen->name) ?: "Pen {$pen->id}";
            if ($name === $pen->name) {
                continue;
            }
            $base = $name;
            for ($n = 2; DB::table('pens')->where('shed_id', $pen->shed_id)->where('id', '<>', $pen->id)->whereRaw('lower(name) = lower(?)', [$name])->exists(); $n++) {
                $name = "$base ($n)";
            }
            DB::table('pens')->where('id', $pen->id)->update(['name' => $name, 'previous_name' => $pen->name]);
        }
    }

    public function down(): void
    {
        DB::table('pens')->whereNotNull('previous_name')->update(['name' => DB::raw('previous_name')]);
        Schema::table('pens', fn (Blueprint $t) => $t->dropColumn('previous_name'));
    }
};
