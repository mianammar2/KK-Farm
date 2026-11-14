<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-290: the Disease Master takes the owner's workbook fields (code, local name, class, priority, isolation, reporting,
 * symptoms, first farm action, care pathway, prevention, diagnostics, source) and its 25 diseases are imported into
 * every farm (by code, else by name; nothing duplicated). A sale records why the goat was sold: business, profit, sick
 * (with the disease) or other (with the reason typed).
 */
return new class extends Migration
{
    private const FIELDS = ['code', 'local_name', 'disease_class', 'record_type', 'priority', 'isolation', 'reporting', 'symptoms', 'farm_action', 'care_pathway', 'prevention', 'diagnostics', 'source_url'];

    public function up(): void
    {
        Schema::table('diseases', function (Blueprint $t) {
            $t->string('code', 20)->nullable();
            $t->string('local_name', 120)->nullable();
            $t->string('disease_class', 80)->nullable();
            $t->string('record_type', 30)->nullable();
            $t->string('priority', 20)->nullable();
            $t->string('isolation', 30)->nullable();
            $t->string('reporting', 30)->nullable();
            $t->text('symptoms')->nullable();
            $t->text('farm_action')->nullable();
            $t->text('care_pathway')->nullable();
            $t->text('prevention')->nullable();
            $t->text('diagnostics')->nullable();
            $t->string('source_url', 300)->nullable();
        });
        Schema::table('animal_movements', function (Blueprint $t) {
            $t->string('sale_reason', 12)->nullable(); // business | profit | sick | other
            $t->foreignId('disease_id')->nullable()->constrained('diseases')->nullOnDelete();
            $t->string('sale_reason_note', 200)->nullable();
        });

        $rows = require config_path('kkfarm_diseases.php');
        foreach (DB::table('organizations')->pluck('id') as $org) {
            DB::select("select set_config('app.current_org', ?, false)", [$org]);
            foreach ($rows as $r) {
                $match = DB::table('diseases')->where('code', $r['code'])->first()
                    ?? DB::table('diseases')->whereRaw('lower(name) = lower(?)', [$r['name']])->first()
                    ?? DB::table('diseases')->whereRaw('lower(name) = lower(?)', [$r['local_name']])->first();
                $values = array_intersect_key($r, array_flip(self::FIELDS)) + [
                    'name' => $r['name'], 'category' => $r['category'],
                    'zoonotic' => in_array($r['zoonotic'], ['Yes', 'Potential'], true), 'notifiable' => $r['reporting'] === 'Yes', 'active' => true, 'updated_at' => now(),
                ];
                if ($match) {
                    DB::table('diseases')->where('id', $match->id)->update($values);
                } else {
                    DB::table('diseases')->insert($values + ['org_id' => $org, 'created_at' => now()]);
                }
            }
        }
        DB::select("select set_config('app.current_org', '', false)");
    }

    public function down(): void
    {
        Schema::table('animal_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('disease_id');
            $t->dropColumn(['sale_reason', 'sale_reason_note']);
        });
        Schema::table('diseases', fn (Blueprint $t) => $t->dropColumn(self::FIELDS));
    }
};
