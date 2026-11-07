<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * D-037: shared database, organization id on every tenant-owned table, enforced by PostgreSQL Row-Level Security.
 *
 * - `org_id` defaults to app_current_org(), the tenant bound to the session, so existing insert code keeps working.
 * - Policies use USING and WITH CHECK, so a tenant can neither read nor write another tenant's rows.
 * - FORCE ROW LEVEL SECURITY makes the policies apply to the table owner too. Superusers always bypass RLS,
 *   which is why the application switches to the non-superuser `kkfarm_app` role (see App\Support\Tenant).
 */
return new class extends Migration
{
    /** Tenant-owned tables. A test fails if a table with an org_id column is missing RLS. */
    private const TABLES = [
        'breeds', 'sheds', 'pens', 'feeding_groups', 'animals', 'rfid_tags', 'rfid_assignments', 'animal_events',
        'weight_records', 'health_records', 'matings', 'kiddings', 'feeding_logs', 'employees', 'tasks',
        'ledger_entries', 'animal_photos', 'activities',
    ];

    /** Uniqueness that used to be global and is now per organization. */
    private const UNIQUES = [
        'breeds' => 'name', 'sheds' => 'code', 'animals' => 'farm_number', 'rfid_tags' => 'tag_code', 'employees' => 'code',
    ];

    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('slug')->unique();
            $t->timestamps();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_current_org() RETURNS uuid
            LANGUAGE sql STABLE AS $$ SELECT NULLIF(current_setting('app.current_org', true), '')::uuid $$
        SQL);

        // Rows created before tenancy existed belong to one default organization.
        $defaultOrg = null;
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                $defaultOrg = (string) Str::uuid();
                DB::table('organizations')->insert(['id' => $defaultOrg, 'name' => 'Default organization', 'slug' => 'default', 'created_at' => now(), 'updated_at' => now()]);
                break;
            }
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->uuid('org_id')->nullable());
            DB::statement("ALTER TABLE $table ALTER COLUMN org_id SET DEFAULT app_current_org()");
            if ($defaultOrg) {
                DB::table($table)->update(['org_id' => $defaultOrg]);
            }
            DB::statement("ALTER TABLE $table ALTER COLUMN org_id SET NOT NULL");
            Schema::table($table, function (Blueprint $t) {
                $t->foreign('org_id')->references('id')->on('organizations');
                $t->index('org_id');
            });
            $this->protect($table, 'org_id');
        }

        foreach (self::UNIQUES as $table => $column) {
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->dropUnique([$column]);
                $t->unique(['org_id', $column]);
            });
        }

        // Users are global identities that belong to an organization. No RLS here: sign-in must find the user first.
        Schema::table('users', function (Blueprint $t) {
            $t->uuid('org_id')->nullable()->index();
            $t->foreign('org_id')->references('id')->on('organizations');
        });
        DB::statement('ALTER TABLE users ALTER COLUMN org_id SET DEFAULT app_current_org()');
        if ($defaultOrg) {
            DB::table('users')->update(['org_id' => $defaultOrg]);
        }

        // An organization can only see itself.
        $this->protect('organizations', 'id');

        $this->createAppRole();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropForeign(['org_id']);
            $t->dropColumn('org_id');
        });

        foreach (self::UNIQUES as $table => $column) {
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->dropUnique(['org_id', $column]);
                $t->unique([$column]);
            });
        }

        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON organizations');
        DB::statement('ALTER TABLE organizations NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE organizations DISABLE ROW LEVEL SECURITY');

        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON $table");
            DB::statement("ALTER TABLE $table NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table DISABLE ROW LEVEL SECURITY");
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['org_id']);
                $t->dropIndex(['org_id']);
                $t->dropColumn('org_id');
            });
        }

        Schema::dropIfExists('organizations');
        DB::statement('DROP FUNCTION IF EXISTS app_current_org()');
        // The kkfarm_app role is cluster-wide and may serve other databases, so it is left in place.
    }

    private function protect(string $table, string $column): void
    {
        DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY tenant_isolation ON $table USING ($column = app_current_org()) WITH CHECK ($column = app_current_org())");
    }

    /**
     * A role that can never bypass RLS. NOLOGIN here because the app switches into it with SET ROLE after connecting.
     * A production deployment can instead give it a password and connect as it directly.
     */
    private function createAppRole(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'kkfarm_app') THEN
                    CREATE ROLE kkfarm_app NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
                END IF;
                EXECUTE 'GRANT kkfarm_app TO ' || quote_ident(current_user);
            END
            $$;
            GRANT USAGE ON SCHEMA public TO kkfarm_app;
            GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO kkfarm_app;
            GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO kkfarm_app;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO kkfarm_app;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO kkfarm_app;
        SQL);
    }
};
