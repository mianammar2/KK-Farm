<?php

use App\Models\Mating;
use App\Support\KiddingAlert;
use App\Support\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-103: the kidding alert starts 10 days before the expected kidding (was 14); current pregnancies get their task. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->unsignedSmallInteger('kidding_alert_days')->default(10)->change());
        DB::table('organizations')->where('kidding_alert_days', 14)->update(['kidding_alert_days' => 10]);
        foreach (DB::table('organizations')->pluck('id') as $org) {
            Tenant::enter($org);
            try {
                Mating::where('status', 'pregnant')->get()->each(fn (Mating $m) => KiddingAlert::sync($m));
            } finally {
                Tenant::leave();
            }
        }
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->unsignedSmallInteger('kidding_alert_days')->default(14)->change());
    }
};
