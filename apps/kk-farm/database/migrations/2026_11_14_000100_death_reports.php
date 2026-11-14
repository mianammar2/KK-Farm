<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-260: a death is a veterinary finding. The vet reports it (time, cause, what was seen); the administrator approves it,
 * and only then does the goat leave the farm. The report stays as the start of the goat's death review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('death_reports', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->uuid('animal_id');
            $t->dateTime('died_at');
            $t->string('cause', 120);
            $t->text('circumstances')->nullable(); // what was seen
            $t->text('postmortem')->nullable(); // findings, if a postmortem was done
            $t->string('status', 12)->default('pending'); // pending | approved | rejected
            $t->string('reported_by', 80);
            $t->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('decided_by', 80)->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 300)->nullable();
            $t->unsignedBigInteger('movement_id')->nullable();
            $t->timestamps();
            $t->index(['animal_id', 'status']);
        });
        DB::statement('ALTER TABLE death_reports ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE death_reports FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON death_reports USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE death_reports ADD CONSTRAINT death_reports_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
        DB::statement('ALTER TABLE death_reports ADD CONSTRAINT death_reports_animal_fk FOREIGN KEY (animal_id) REFERENCES animals(id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('death_reports');
    }
};
