<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-038: immutable event history with a common envelope.
 *
 * - Envelope on every history table: source, device_id, recorded_at, supersedes_id (corrections), event_uuid (idempotency).
 * - The database refuses UPDATE of fact columns and every DELETE on history tables (trigger). Only declared
 *   projection columns (a cached status that can be rebuilt from events) may change.
 * - New event tables carry what used to be overwritten: animal_moves (pen), pregnancy_checks, health_record_closures.
 * - Views expose the current (not superseded) rows, the derived state, and the derived pen headcount.
 */
return new class extends Migration
{
    /** Existing history tables and the cached columns that may still be updated (rebuildable from events). */
    private const EXISTING = [
        'animal_events' => [], 'weight_records' => [], 'kiddings' => [],
        'health_records' => ['status', 'updated_at'], 'matings' => ['status', 'updated_at'],
    ];

    private const NEW_TABLES = ['animal_moves', 'pregnancy_checks', 'health_record_closures'];

    private const SOURCES = "'manual','rfid','sensor','camera','rule','import'";

    public function up(): void
    {
        foreach (array_keys(self::EXISTING) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('source')->default('manual');
                $t->string('device_id')->nullable();
                $t->timestampTz('recorded_at')->useCurrent();
                $t->unsignedBigInteger('supersedes_id')->nullable();
                $t->uuid('event_uuid')->nullable();
            });
            DB::table($table)->update(['recorded_at' => DB::raw('created_at')]);
            $this->constrain($table);
        }

        // Whether a health record opens a case. The case is open until a closure event exists.
        Schema::table('health_records', fn (Blueprint $t) => $t->boolean('opens_case')->default(false));
        DB::table('health_records')->where('status', 'open')->update(['opens_case' => true]);

        Schema::create('animal_moves', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignUuid('animal_id')->constrained('animals');
            $t->foreignId('from_pen_id')->nullable()->constrained('pens');
            $t->foreignId('to_pen_id')->nullable()->constrained('pens'); // null = left the farm
            $t->timestampTz('occurred_at');
            $t->string('reason');
            $t->string('recorded_by');
            $this->envelope($t);
            $t->index(['animal_id', 'occurred_at']);
        });

        Schema::create('pregnancy_checks', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('mating_id')->constrained('matings');
            $t->date('checked_on');
            $t->string('result'); // pregnant | not_pregnant | inconclusive
            $t->string('method')->nullable();
            $t->string('recorded_by');
            $this->envelope($t);
            $t->index(['mating_id', 'checked_on']);
        });

        Schema::create('health_record_closures', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('health_record_id')->constrained('health_records');
            $t->date('closed_on');
            $t->text('notes')->nullable();
            $t->string('recorded_by');
            $this->envelope($t);
        });

        foreach (self::NEW_TABLES as $table) {
            $this->constrain($table);
            $this->protectTenant($table);
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE pregnancy_checks ADD CONSTRAINT pregnancy_checks_result_valid CHECK (result IN ('pregnant','not_pregnant','inconclusive'));

            CREATE OR REPLACE FUNCTION forbid_history_change() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE allowed text[] := TG_ARGV;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION '% is append-only: rows cannot be deleted. Record a correction instead.', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
                END IF;
                IF (to_jsonb(NEW) - allowed) IS DISTINCT FROM (to_jsonb(OLD) - allowed) THEN
                    RAISE EXCEPTION '% is append-only: facts cannot be edited. Record a correction instead.', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END
            $$;
        SQL);

        foreach (self::EXISTING + array_fill_keys(self::NEW_TABLES, []) as $table => $projection) {
            $args = $projection ? "'".implode("','", $projection)."'" : '';
            DB::statement("CREATE TRIGGER history_is_append_only BEFORE UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION forbid_history_change($args)");
        }

        $this->createViews();
        DB::unprepared('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO kkfarm_app; GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO kkfarm_app;');
    }

    public function down(): void
    {
        $this->dropViews();
        $tables = array_merge(array_keys(self::EXISTING), self::NEW_TABLES);
        foreach ($tables as $table) {
            DB::statement("DROP TRIGGER IF EXISTS history_is_append_only ON $table");
        }
        DB::statement('DROP FUNCTION IF EXISTS forbid_history_change()');

        foreach (array_reverse(self::NEW_TABLES) as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('health_records', fn (Blueprint $t) => $t->dropColumn('opens_case'));
        foreach (array_keys(self::EXISTING) as $table) {
            DB::statement("DROP INDEX IF EXISTS {$table}_org_event_uuid");
            DB::statement("DROP INDEX IF EXISTS {$table}_one_correction");
            DB::statement("ALTER TABLE $table DROP CONSTRAINT IF EXISTS {$table}_source_valid");
            DB::statement("ALTER TABLE $table DROP CONSTRAINT IF EXISTS {$table}_supersedes_fk");
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['source', 'device_id', 'recorded_at', 'supersedes_id', 'event_uuid']);
            });
        }
    }

    private function tenant(Blueprint $t): void
    {
        $t->uuid('org_id')->default(DB::raw('app_current_org()'));
        $t->foreign('org_id')->references('id')->on('organizations');
        $t->index('org_id');
    }

    private function envelope(Blueprint $t): void
    {
        $t->string('source')->default('manual');
        $t->string('device_id')->nullable();
        $t->timestampTz('recorded_at')->useCurrent();
        $t->unsignedBigInteger('supersedes_id')->nullable();
        $t->uuid('event_uuid')->nullable();
    }

    /** Source check, a linear correction chain, and one row per client event id per organization. */
    private function constrain(string $table): void
    {
        DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_source_valid CHECK (source IN (".self::SOURCES.'))');
        DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_supersedes_fk FOREIGN KEY (supersedes_id) REFERENCES $table (id)");
        DB::statement("CREATE UNIQUE INDEX {$table}_one_correction ON $table (supersedes_id) WHERE supersedes_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX {$table}_org_event_uuid ON $table (org_id, event_uuid) WHERE event_uuid IS NOT NULL");
    }

    private function protectTenant(string $table): void
    {
        DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY tenant_isolation ON $table USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
    }

    private function createViews(): void
    {
        $tables = array_merge(array_keys(self::EXISTING), self::NEW_TABLES);
        foreach ($tables as $table) {
            // security_invoker: row level security is evaluated for the caller, not the view owner.
            DB::statement("CREATE VIEW current_$table WITH (security_invoker = true) AS SELECT t.* FROM $table t WHERE NOT EXISTS (SELECT 1 FROM $table s WHERE s.supersedes_id = t.id)");
        }

        DB::unprepared(<<<'SQL'
            CREATE VIEW animal_current_pen WITH (security_invoker = true) AS
            SELECT DISTINCT ON (m.animal_id) m.animal_id, m.org_id, m.to_pen_id AS pen_id
            FROM current_animal_moves m
            ORDER BY m.animal_id, m.occurred_at DESC, m.id DESC;

            CREATE VIEW pen_headcount WITH (security_invoker = true) AS
            SELECT p.id AS pen_id, p.org_id, count(a.id) FILTER (WHERE a.status = 'active') AS headcount
            FROM pens p
            LEFT JOIN animal_current_pen cp ON cp.pen_id = p.id
            LEFT JOIN animals a ON a.id = cp.animal_id
            GROUP BY p.id, p.org_id;

            CREATE VIEW mating_derived_status WITH (security_invoker = true) AS
            SELECT m.id AS mating_id, m.org_id,
                CASE WHEN EXISTS (SELECT 1 FROM current_kiddings k WHERE k.mating_id = m.id) THEN 'kidded'
                     ELSE COALESCE((SELECT CASE c.result WHEN 'pregnant' THEN 'pregnant' WHEN 'not_pregnant' THEN 'not_pregnant' ELSE 'open' END
                                    FROM current_pregnancy_checks c WHERE c.mating_id = m.id
                                    ORDER BY c.checked_on DESC, c.id DESC LIMIT 1), 'open')
                END AS status
            FROM current_matings m;

            CREATE VIEW health_record_derived_status WITH (security_invoker = true) AS
            SELECT h.id AS health_record_id, h.org_id,
                CASE WHEN h.opens_case AND NOT EXISTS (SELECT 1 FROM current_health_record_closures c WHERE c.health_record_id = h.id)
                     THEN 'open' ELSE 'closed' END AS status
            FROM current_health_records h;
        SQL);
    }

    private function dropViews(): void
    {
        foreach (['health_record_derived_status', 'mating_derived_status', 'pen_headcount', 'animal_current_pen'] as $view) {
            DB::statement("DROP VIEW IF EXISTS $view");
        }
        foreach (array_merge(self::NEW_TABLES, array_keys(self::EXISTING)) as $table) {
            DB::statement("DROP VIEW IF EXISTS current_$table");
        }
    }
};
