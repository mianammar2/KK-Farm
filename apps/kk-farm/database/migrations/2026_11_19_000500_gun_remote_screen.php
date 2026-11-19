<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-355: remote screen of a gun (its latest screenshot, replaced each time), commands with details (where to tap), and
 * the gun's own key: issued on its first report with a signed-in session, then it reports with the key, so it stays
 * reachable when nobody is signed in or the app is closed (the scan service reports).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gun_devices', function (Blueprint $t) {
            $t->text('screen')->nullable(); // JPEG, base64
            $t->unsignedInteger('screen_w')->nullable();
            $t->unsignedInteger('screen_h')->nullable();
            $t->timestamp('screen_at')->nullable();
            $t->jsonb('can')->nullable(); // what this gun's app can do: remote control on, self-update allowed
            $t->string('key_hash', 64)->nullable()->unique();
        });
        DB::unprepared(<<<'SQL'
            -- A gun reporting with its key is not signed in, so its farm is found from the key before the tenant is set.
            -- Only this lookup crosses tenants, and it returns nothing but the farm and gun of a valid key.
            CREATE OR REPLACE FUNCTION gun_for_key(p_hash text) RETURNS TABLE(org_id uuid, gun_id bigint)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT g.org_id, g.id FROM gun_devices g WHERE g.key_hash = p_hash LIMIT 1
            $$;
            REVOKE ALL ON FUNCTION gun_for_key(text) FROM PUBLIC;
        SQL);
        if (DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'kkfarm_app'")) {
            DB::statement('GRANT EXECUTE ON FUNCTION gun_for_key(text) TO kkfarm_app');
        }
        Schema::table('gun_commands', function (Blueprint $t) {
            $t->jsonb('args')->nullable();
        });
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS gun_for_key(text)');
        Schema::table('gun_commands', fn (Blueprint $t) => $t->dropColumn('args'));
        Schema::table('gun_devices', fn (Blueprint $t) => $t->dropColumn(['screen', 'screen_w', 'screen_h', 'screen_at', 'can', 'key_hash']));
    }
};
