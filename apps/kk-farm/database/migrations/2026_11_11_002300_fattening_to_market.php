<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-197: journey H Fattening-to-Market. Meat selections (a breeder cull, feeder or slow grower proposed for the meat
 * business and approved by someone else) and animal-level costing on finishing goats (health cost, feed basis).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meat_selections', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignUuid('animal_id')->constrained('animals');
            $t->string('reason', 20); // cull_breeder | feeder | slow_grower | other
            $t->string('notes', 500)->nullable();
            $t->string('status', 12)->default('proposed'); // proposed | approved | rejected | batched
            $t->string('proposed_by');
            $t->string('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 300)->nullable();
            $t->foreignId('batch_id')->nullable()->constrained('fattening_batches');
            $t->timestamps();
            $t->index(['animal_id', 'status']);
        });
        DB::statement('ALTER TABLE meat_selections ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE meat_selections FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON meat_selections USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE meat_selections ADD CONSTRAINT meat_selections_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');

        Schema::table('fattening_batch_animals', function (Blueprint $t) {
            $t->bigInteger('health_cost_minor')->nullable(); // treatments + lab while in the batch, fixed at exit
            $t->string('feed_basis', 10)->nullable();       // actual (meals fed) | estimate (ration)
        });
    }

    public function down(): void
    {
        Schema::table('fattening_batch_animals', fn (Blueprint $t) => $t->dropColumn(['health_cost_minor', 'feed_basis']));
        Schema::drop('meat_selections');
    }
};
