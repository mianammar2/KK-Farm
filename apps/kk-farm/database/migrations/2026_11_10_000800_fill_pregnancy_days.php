<?php

use App\Support\Checkups;
use App\Support\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** D-101: checkups already entered for a pregnant doe get their day of pregnancy, counted from the mating. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('organizations')->pluck('id') as $org) {
            Tenant::enter($org);
            try {
                Checkups::fillPregnancyDays();
            } finally {
                Tenant::leave();
            }
        }
    }

    public function down(): void
    {
        // Filled days stay: they follow from the mating date and are correct either way.
    }
};
