<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-043: a parent can be entered by ear tag before (or without) being registered, like the workbook's
 * Sire Tag / Dam Tag columns. When a goat with that tag is registered later, the child is linked to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animals', function (Blueprint $t) {
            $t->string('sire_tag', 40)->nullable()->index();
            $t->string('dam_tag', 40)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('animals', function (Blueprint $t) {
            $t->dropIndex(['sire_tag']);
            $t->dropIndex(['dam_tag']);
            $t->dropColumn(['sire_tag', 'dam_tag']);
        });
    }
};
