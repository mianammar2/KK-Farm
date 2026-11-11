<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-201: journey L Genetic Selection-to-Replacement. Inbreeding (COI of the planned kid) on every mating with an
 * override reason above the farm limit; replacement decisions keep the merit snapshot, the planned sire and its COI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matings', function (Blueprint $t) {
            $t->decimal('coi_pct', 5, 2)->nullable();
            $t->string('coi_override_reason', 300)->nullable();
        });
        Schema::table('breeder_selections', function (Blueprint $t) {
            $t->foreignUuid('planned_sire_id')->nullable()->constrained('animals');
            $t->decimal('planned_coi_pct', 5, 2)->nullable();
            $t->foreignId('pen_id')->nullable()->constrained('pens');
            $t->json('merit')->nullable();
        });
        Schema::table('organizations', function (Blueprint $t) {
            $t->decimal('inbreeding_limit_pct', 5, 2)->default(6.25); // first-cousin level; the breeding lead may change it
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('inbreeding_limit_pct'));
        Schema::table('breeder_selections', function (Blueprint $t) {
            $t->dropConstrainedForeignId('planned_sire_id');
            $t->dropConstrainedForeignId('pen_id');
            $t->dropColumn(['planned_coi_pct', 'merit']);
        });
        Schema::table('matings', fn (Blueprint $t) => $t->dropColumn(['coi_pct', 'coi_override_reason']));
    }
};
