<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-369: a medicine's standard dose, set by the vet on the medicine (from the label): the amount per goat in the
 * medicine's unit, route, how often, for how many days, and the label's meat and milk withdrawal days. Medication
 * forms fill these in when the medicine is chosen; the vet checks and can change every field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $t) {
            $t->decimal('dose_qty', 10, 2)->nullable();
            $t->string('dose_route', 20)->nullable();
            $t->string('dose_frequency', 20)->nullable();
            $t->unsignedSmallInteger('dose_days')->nullable();
            $t->unsignedSmallInteger('meat_withdrawal_days')->nullable();
            $t->unsignedSmallInteger('milk_withdrawal_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $t) {
            $t->dropColumn(['dose_qty', 'dose_route', 'dose_frequency', 'dose_days', 'meat_withdrawal_days', 'milk_withdrawal_days']);
        });
    }
};
