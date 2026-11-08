<?php

use App\Models\Shed;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-056: sheds have a unit type (BBU, BDU, ... see Shed::TYPES) and are named PREFIX-TYPE-NN, e.g. KKF-BDU-01.
 * Existing sheds whose name already contains a unit code are converted ("kk-bdu-005" → "KKF-BDU-05");
 * others keep their name until someone picks a type. The old name is kept in previous_name for rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->string('shed_prefix', 10)->default('KKF'));
        Schema::table('sheds', function (Blueprint $t) {
            $t->string('type', 3)->nullable();
            $t->string('previous_name')->nullable();
        });

        $codes = implode('|', array_keys(Shed::TYPES));
        foreach (DB::table('sheds')->orderBy('id')->get() as $shed) {
            if (! preg_match("/(?:^|[^A-Z])($codes)(?:[^A-Z]+0*(\\d+))?/i", "{$shed->name} {$shed->code}", $m)) {
                continue;
            }
            $type = strtoupper($m[1]);
            $prefix = DB::table('organizations')->where('id', $shed->org_id)->value('shed_prefix') ?: 'KKF';
            $name = sprintf('%s-%s-%02d', $prefix, $type, (int) ($m[2] ?? 0) ?: 1);
            $taken = fn ($n) => DB::table('sheds')->where('org_id', $shed->org_id)->where('id', '<>', $shed->id)->where(fn ($q) => $q->where('name', $n)->orWhere('code', $n))->exists();
            if ($taken($name)) {
                for ($i = 1; $taken($name = sprintf('%s-%s-%02d', $prefix, $type, $i)); $i++);
            }
            DB::table('sheds')->where('id', $shed->id)->update(['type' => $type, 'name' => $name, 'code' => $name, 'previous_name' => $shed->name.'|'.$shed->code]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('sheds')->whereNotNull('previous_name')->get() as $shed) {
            [$name, $code] = explode('|', $shed->previous_name, 2) + [1 => $shed->code];
            DB::table('sheds')->where('id', $shed->id)->update(['name' => $name, 'code' => $code]);
        }
        Schema::table('sheds', fn (Blueprint $t) => $t->dropColumn(['type', 'previous_name']));
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('shed_prefix'));
    }
};
