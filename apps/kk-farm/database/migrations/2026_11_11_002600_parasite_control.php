<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-200: journey K Parasite Surveillance-to-Control. Faecal egg counts (FEC) and FAMACHA scores, the vet's decision
 * per sample (treat with a vet-set withdrawal and retest date, monitor, or no treatment) and the retest's reduction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parasite_samples', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->unsignedInteger('sample_no');
            $t->foreignUuid('animal_id')->nullable()->constrained('animals');
            $t->foreignId('pen_id')->nullable()->constrained('pens'); // pooled pen sample
            $t->date('sampled_on');
            $t->string('reason', 12);   // clinical | seasonal | suspected | retest | monitoring
            $t->unsignedInteger('epg')->nullable();             // eggs per gram
            $t->unsignedTinyInteger('famacha')->nullable();     // 1–5
            $t->unsignedTinyInteger('bcs')->nullable();
            $t->string('lab', 120)->nullable();
            $t->bigInteger('cost_minor')->nullable();
            $t->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $t->foreignId('retest_of_id')->nullable()->constrained('parasite_samples');
            $t->decimal('reduction_pct', 5, 1)->nullable();
            $t->boolean('resistance_flag')->default(false);
            $t->string('decision', 10)->nullable(); // treat | monitor | no_treat
            $t->string('decision_note', 500)->nullable();
            $t->string('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->date('retest_due_on')->nullable();
            $t->unsignedInteger('treated')->default(0);
            $t->string('notes', 500)->nullable();
            $t->string('recorded_by');
            $t->timestamps();
        });
        DB::statement('ALTER TABLE parasite_samples ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE parasite_samples FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON parasite_samples USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE parasite_samples ADD CONSTRAINT parasite_samples_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');

        Schema::table('treatments', function (Blueprint $t) {
            $t->unsignedBigInteger('health_case_id')->nullable()->change(); // a targeted dose after a test needs no case
            $t->foreignId('parasite_sample_id')->nullable()->constrained('parasite_samples');
        });
        Schema::table('organizations', function (Blueprint $t) {
            $t->unsignedInteger('fec_treat_epg')->nullable();     // the vet's guide: treat at or above this EPG
            $t->unsignedTinyInteger('famacha_treat')->nullable(); // and at or above this FAMACHA score
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['fec_treat_epg', 'famacha_treat']));
        Schema::table('treatments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parasite_sample_id');
        });
        Schema::drop('parasite_samples');
    }
};
