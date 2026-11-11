<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-211: App 17 optional milk processing (batches made from milk sent to processing, heat treatment, yield, release by
 * a second person), App 18 feeder purchase lots (bought for meat: proposed for finishing when cleared from quarantine),
 * and App 19 humane end of life (request → vet assessment → owner/manager authorisation → vet performs → death recorded).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_processing_batches', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('code', 20);
            $t->foreignId('dispatch_id')->constrained('milk_dispatches');
            $t->string('product', 16); // yogurt | cheese | ghee | khoa | pasteurised | lassi | other
            $t->decimal('input_l', 8, 2);
            $t->decimal('heat_temp_c', 4, 1)->nullable();
            $t->unsignedSmallInteger('heat_minutes')->nullable();
            $t->decimal('output_qty', 8, 2);
            $t->string('output_unit', 4); // kg | L
            $t->date('made_on');
            $t->date('best_before')->nullable();
            $t->string('status', 10)->default('made'); // made | released | discarded
            $t->string('made_by');
            $t->string('released_by')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->string('notes', 300)->nullable();
            $t->timestamps();
            $t->unique(['org_id', 'code']);
        });
        Schema::table('animal_purchase_lots', fn (Blueprint $t) => $t->string('kind', 10)->default('breeding')); // breeding | feeder
        Schema::create('end_of_life_reviews', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignUuid('animal_id')->constrained('animals');
            $t->foreignId('welfare_case_id')->nullable()->constrained('welfare_cases');
            $t->string('reason', 20);
            $t->string('details', 1000);
            $t->string('requested_by');
            $t->string('vet_assessment', 1000)->nullable();
            $t->boolean('vet_recommends')->nullable();
            $t->string('vet_by')->nullable();
            $t->timestamp('vet_at')->nullable();
            $t->string('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->string('status', 12)->default('requested'); // requested | assessed | approved | declined | done | withdrawn
            $t->string('method', 200)->nullable();
            $t->string('disposition', 120)->nullable();
            $t->string('performed_by')->nullable();
            $t->timestamp('performed_at')->nullable();
            $t->foreignId('movement_id')->nullable()->constrained('animal_movements');
            $t->timestamps();
        });
        foreach (['milk_processing_batches', 'end_of_life_reviews'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
    }

    public function down(): void
    {
        Schema::drop('end_of_life_reviews');
        Schema::table('animal_purchase_lots', fn (Blueprint $t) => $t->dropColumn('kind'));
        Schema::drop('milk_processing_batches');
    }
};
