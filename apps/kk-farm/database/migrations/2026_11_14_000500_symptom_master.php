<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-292: the Symptom Master and the symptom-disease links from the owner's workbook, imported into every farm; a health
 * case keeps the symptoms the vet ticked and the disease it was linked to (Disease Master). Symptoms are observations,
 * not diagnoses.
 */
return new class extends Migration
{
    private function rls(string $table): void
    {
        DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY tenant_isolation ON $table USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
        DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
    }

    public function up(): void
    {
        Schema::create('symptoms', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('code', 20)->nullable();
            $t->string('system', 40);
            $t->string('name', 120);
            $t->string('local_name', 120)->nullable();
            $t->string('input_type', 80)->nullable();
            $t->string('urgency', 200)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        $this->rls('symptoms');
        Schema::create('disease_symptom', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('disease_id')->constrained('diseases')->cascadeOnDelete();
            $t->foreignId('symptom_id')->constrained('symptoms')->cascadeOnDelete();
            $t->unique(['disease_id', 'symptom_id']);
        });
        $this->rls('disease_symptom');
        Schema::create('health_case_symptoms', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('health_case_id')->constrained('health_cases')->cascadeOnDelete();
            $t->foreignId('symptom_id')->constrained('symptoms');
            $t->timestamps();
            $t->unique(['health_case_id', 'symptom_id']);
        });
        $this->rls('health_case_symptoms');
        Schema::table('health_cases', fn (Blueprint $t) => $t->foreignId('disease_id')->nullable()->constrained('diseases')->nullOnDelete());

        $master = require config_path('kkfarm_symptoms.php');
        foreach (DB::table('organizations')->pluck('id') as $org) {
            DB::select("select set_config('app.current_org', ?, false)", [$org]);
            $ids = [];
            foreach ($master['symptoms'] as $s) {
                $id = DB::table('symptoms')->where('code', $s['code'])->value('id');
                $values = $s + ['active' => true, 'updated_at' => now()];
                if ($id) {
                    DB::table('symptoms')->where('id', $id)->update($values);
                } else {
                    $id = DB::table('symptoms')->insertGetId($values + ['org_id' => $org, 'created_at' => now()]);
                }
                $ids[$s['code']] = $id;
            }
            foreach ($master['links'] as $disease => $codes) {
                $did = DB::table('diseases')->where('code', $disease)->value('id');
                foreach ($did ? $codes : [] as $c) {
                    DB::table('disease_symptom')->insertOrIgnore(['org_id' => $org, 'disease_id' => $did, 'symptom_id' => $ids[$c]]);
                }
            }
            // existing cases: link them to the Disease Master by name
            DB::statement('update health_cases c set disease_id = d.id from diseases d where c.disease_id is null and lower(d.name) = lower(c.condition)');
        }
        DB::select("select set_config('app.current_org', '', false)");
    }

    public function down(): void
    {
        Schema::table('health_cases', fn (Blueprint $t) => $t->dropConstrainedForeignId('disease_id'));
        Schema::dropIfExists('health_case_symptoms');
        Schema::dropIfExists('disease_symptom');
        Schema::dropIfExists('symptoms');
    }
};
