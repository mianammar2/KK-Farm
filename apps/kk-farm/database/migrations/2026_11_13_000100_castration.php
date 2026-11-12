<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-230: classification phase 2. A castration is recorded by the vet as a health record (the event, append-only);
 * the goat keeps the date so it is a wether from then on and can never be chosen as a sire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animals', function (Blueprint $t) {
            $t->date('castrated_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('animals', fn (Blueprint $t) => $t->dropColumn('castrated_on'));
    }
};
