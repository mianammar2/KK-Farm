<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-207: App 07 gaps: pen status, BR-004 overcapacity block with authorised override, pen cleaning records. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pens', function (Blueprint $t) {
            $t->string('status', 12)->default('active'); // active | cleaning | maintenance | closed
            $t->string('status_reason', 300)->nullable();
            $t->string('status_by')->nullable();
            $t->timestamp('status_at')->nullable();
        });
        Schema::table('animal_movements', function (Blueprint $t) {
            $t->string('capacity_override', 300)->nullable(); // BR-004: why a full pen took one more, and who
        });
        Schema::create('pen_cleanings', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('pen_id')->constrained('pens');
            $t->string('kind', 14); // routine | deep | disinfection | after_outbreak | after_loss
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->string('disinfectant', 120)->nullable();
            $t->unsignedSmallInteger('contact_minutes')->nullable();
            $t->string('notes', 500)->nullable();
            $t->string('done_by');
            $t->string('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
        });
        DB::statement('ALTER TABLE pen_cleanings ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE pen_cleanings FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON pen_cleanings USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE pen_cleanings ADD CONSTRAINT pen_cleanings_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');
    }

    public function down(): void
    {
        Schema::drop('pen_cleanings');
        Schema::table('animal_movements', fn (Blueprint $t) => $t->dropColumn('capacity_override'));
        Schema::table('pens', fn (Blueprint $t) => $t->dropColumn(['status', 'status_reason', 'status_by', 'status_at']));
    }
};
