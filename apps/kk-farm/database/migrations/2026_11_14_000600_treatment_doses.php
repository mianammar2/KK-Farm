<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-304: every dose of a prescription (workbook Vet_Orders → Medicine_Admin): planned from how often and for how
 * many days, then given (from stock, first-expiring batch), missed or held, with any adverse reaction. The first dose
 * is the treatment itself. Rows are never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_doses', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('treatment_id')->constrained('treatments')->cascadeOnDelete();
            $t->uuid('animal_id');
            $t->unsignedSmallInteger('number'); // 1 = the first dose
            $t->date('due_on');
            $t->string('slot', 10)->nullable(); // morning | midday | evening
            $t->string('status', 10)->default('due'); // due | given | missed | held
            $t->timestamp('done_at')->nullable();
            $t->decimal('qty', 10, 2)->nullable();
            $t->string('batch', 60)->nullable();
            $t->string('done_by', 80)->nullable();
            $t->boolean('adverse')->default(false);
            $t->string('notes', 300)->nullable();
            $t->timestamps();
            $t->index(['status', 'due_on']);
            $t->unique(['treatment_id', 'number']);
        });
        DB::statement('ALTER TABLE treatment_doses ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE treatment_doses FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON treatment_doses USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE treatment_doses ADD CONSTRAINT treatment_doses_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
        DB::statement('ALTER TABLE treatment_doses ADD CONSTRAINT treatment_doses_animal_fk FOREIGN KEY (animal_id) REFERENCES animals(id)');
        Schema::table('treatments', function (Blueprint $t) {
            $t->decimal('qty_per_dose', 10, 2)->nullable(); // D-304: what each later dose takes from stock
            $t->date('review_on')->nullable(); // workbook Vet_Orders "Review Date"
        });
    }

    public function down(): void
    {
        Schema::table('treatments', fn (Blueprint $t) => $t->dropColumn(['qty_per_dose', 'review_on']));
        Schema::dropIfExists('treatment_doses');
    }
};
