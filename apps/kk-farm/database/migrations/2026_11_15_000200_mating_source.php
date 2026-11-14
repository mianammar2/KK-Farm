<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-295: where a mating record came from. 'mating' = a mating recorded on the farm (the doe was put to a buck);
 * 'checkup' = a doe found pregnant at a health checkup (date estimated); 'arrival' = pregnant when she came to the farm.
 * Only 'mating' rows are the farm's own matings (Breeding → Mating Records); the others stay as pregnancies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matings', function (Blueprint $t) {
            $t->string('source', 10)->default('mating');
        });
        DB::statement("UPDATE matings SET source = 'checkup' WHERE notes LIKE 'Found pregnant at the health checkup%'");
        DB::statement("UPDATE matings SET source = 'arrival' WHERE notes LIKE 'Pregnant when she came to the farm%'");
    }

    public function down(): void
    {
        Schema::table('matings', fn (Blueprint $t) => $t->dropColumn('source'));
    }
};
