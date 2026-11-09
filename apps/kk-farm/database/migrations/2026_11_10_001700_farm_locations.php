<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-128: farms (locations). Organization → Farm → Shed → Pen. A farm carries where it is (address, city, district,
 * province, country, GPS) and a short code that starts its shed names (KKF-BDU-01). Every organization starts with one
 * farm that takes the existing shed prefix, and all of its sheds move into it, so nothing is renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farms', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('code', 6); // starts the shed names: KKF → KKF-BDU-01
            $t->string('name', 120);
            $t->string('address', 255)->nullable();
            $t->string('city', 80)->nullable();
            $t->string('district', 80)->nullable();
            $t->string('province', 80)->nullable();
            $t->string('country', 80)->nullable();
            $t->decimal('latitude', 9, 6)->nullable();
            $t->decimal('longitude', 9, 6)->nullable();
            $t->string('notes', 500)->nullable();
            $t->boolean('active')->default(true);
            $t->string('created_by')->nullable();
            $t->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX farms_org_code ON farms (org_id, upper(code))');
        DB::statement('ALTER TABLE farms ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE farms FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON farms USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE farms ADD CONSTRAINT farms_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');

        Schema::table('sheds', fn (Blueprint $t) => $t->foreignId('farm_id')->nullable()->constrained('farms'));

        foreach (DB::table('organizations')->get(['id', 'name', 'shed_prefix']) as $org) {
            $farm = DB::table('farms')->insertGetId([
                'org_id' => $org->id, 'code' => strtoupper($org->shed_prefix ?: 'KKF'), 'name' => $org->name, 'country' => 'Pakistan',
                'created_by' => 'System', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('sheds')->where('org_id', $org->id)->update(['farm_id' => $farm]);
        }
    }

    public function down(): void
    {
        Schema::table('sheds', fn (Blueprint $t) => $t->dropConstrainedForeignId('farm_id'));
        Schema::drop('farms');
    }
};
