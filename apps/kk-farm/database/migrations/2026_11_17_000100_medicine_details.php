<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-332: a medicine's label details (specification / active ingredient, use, source), its purchase pack (e.g. a vial
 * of 100 ml while stock and doses are in ml), and the veterinarian's check of the product: confirmed, to confirm, or
 * on hold (a held product cannot be given until a vet confirms it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $t) {
            $t->string('spec', 200)->nullable();
            $t->string('use_case', 200)->nullable();
            $t->string('source', 120)->nullable();
            $t->string('pack_unit', 12)->nullable();
            $t->decimal('pack_size', 10, 2)->nullable();
            $t->string('check_status', 12)->default('confirmed');
            $t->string('check_note', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $t) {
            $t->dropColumn(['spec', 'use_case', 'source', 'pack_unit', 'pack_size', 'check_status', 'check_note']);
        });
    }
};
