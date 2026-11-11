<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** D-205: journey P Incident-to-Corrective Action: incident category, root cause, SOP version, repeat link, quality case. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $t) {
            $t->string('category', 20)->nullable(); // product_quality | animal_safety | financial_control | operations | people_safety
            $t->string('root_cause', 1000)->nullable();
            $t->foreignId('quality_case_id')->nullable()->constrained('quality_cases');
            $t->foreignId('recurrence_of_id')->nullable()->constrained('incidents');
            $t->foreignId('sop_id')->nullable()->constrained('sops');
            $t->unsignedSmallInteger('sop_version')->nullable();     // in force when it happened
            $t->unsignedSmallInteger('sop_version_after')->nullable(); // after the CAPA, if the SOP was revised
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $t) {
            $t->dropConstrainedForeignId('quality_case_id');
            $t->dropConstrainedForeignId('recurrence_of_id');
            $t->dropConstrainedForeignId('sop_id');
            $t->dropColumn(['category', 'root_cause', 'sop_version', 'sop_version_after']);
        });
    }
};
