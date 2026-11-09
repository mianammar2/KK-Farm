<?php

use App\Support\Feed;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-116: Nutrition Standards (Feeding → Nutrition Standards). One row per nutrition group: the CP range the whole
 * daily diet should carry (dry-matter basis), optionally for one breed and a weight range, with TDN, feeding method,
 * meals a day, feed formula and the last review date. Each goat is matched to one automatically from its age, gender,
 * kid stage, pregnancy and purpose; a goat can also be put on a standard by hand (e.g. high-producing lactating).
 * Feeds get a dry matter %, so a ration's Mix CP can be worked out on dry matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_standards', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('name', 80);
            $t->string('category', 30)->nullable(); // Feed::NUTRITION_CATEGORIES key; null = only goats put on it by hand
            $t->foreignId('breed_id')->nullable()->constrained();
            $t->decimal('min_weight_kg', 6, 1)->nullable();
            $t->decimal('max_weight_kg', 6, 1)->nullable();
            $t->decimal('cp_min_pct', 4, 1);
            $t->decimal('cp_max_pct', 4, 1);
            $t->decimal('tdn_min_pct', 4, 1)->nullable();
            $t->decimal('tdn_max_pct', 4, 1)->nullable();
            $t->string('feeding_method', 20)->nullable(); // tmr | separate | grazing
            $t->unsignedTinyInteger('meals_per_day')->nullable();
            $t->string('feed_formula', 40)->nullable();
            $t->string('purpose', 120)->nullable();
            $t->date('reviewed_on')->nullable();
            $t->string('reviewed_by')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        DB::statement('ALTER TABLE nutrition_standards ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE nutrition_standards FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON nutrition_standards USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE nutrition_standards ADD CONSTRAINT nutrition_standards_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');

        Schema::table('animals', fn (Blueprint $t) => $t->foreignId('nutrition_standard_id')->nullable()->constrained());
        Schema::table('feed_items', fn (Blueprint $t) => $t->decimal('dm_pct', 4, 1)->nullable());

        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            Feed::seedStandards($orgId);
        }
    }

    public function down(): void
    {
        Schema::table('feed_items', fn (Blueprint $t) => $t->dropColumn('dm_pct'));
        Schema::table('animals', fn (Blueprint $t) => $t->dropConstrainedForeignId('nutrition_standard_id'));
        Schema::drop('nutrition_standards');
    }
};
