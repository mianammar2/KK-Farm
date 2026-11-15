<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** D-320: a vet's slaughter report goes the same way as a death report (the administrator approves, then the exit). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('death_reports', function (Blueprint $t) {
            $t->string('kind', 20)->default('death'); // death | slaughter
            $t->string('reason', 30)->nullable(); // slaughter: disease, injury, poor_condition, emergency, meat, other
            $t->foreignId('disease_id')->nullable()->constrained('diseases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('death_reports', function (Blueprint $t) {
            $t->dropConstrainedForeignId('disease_id');
            $t->dropColumn(['kind', 'reason']);
        });
    }
};
