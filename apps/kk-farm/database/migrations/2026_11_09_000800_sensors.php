<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-087: room for sensors, cameras and open areas.
 *
 * - Open areas: a shed's outdoor space is a pen of kind "open_area" (OA01). A goat's home pen never changes when it
 *   goes out; going out and coming back in are presence rows (by RFID gate, camera or by hand).
 * - Devices: weighing scales, RFID gates, cameras, air sensors, water/feed/energy meters, each placed in a shed, pen
 *   or open area. A device sends readings to one API with its own key (only a hash is stored).
 * - Readings are append-only raw data. Rules per device type turn them into the same farm records a person makes
 *   (weighings with source "sensor", presence) and into alerts; a vet alert also opens a task for the vets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pens', fn (Blueprint $t) => $t->string('kind', 12)->default('pen')); // pen | open_area

        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('code', 30);
            $t->string('name', 80);
            $t->string('type', 30); // weighing_scale | rfid_gate | camera | environment | water_meter | feed_scale | energy_meter | other
            $t->string('vendor', 60)->nullable();
            $t->string('model', 60)->nullable();
            $t->foreignId('shed_id')->nullable()->constrained('sheds');
            $t->foreignId('pen_id')->nullable()->constrained('pens');
            $t->string('status', 12)->default('planned'); // planned | active | offline | retired
            $t->jsonb('settings')->nullable(); // thresholds, e.g. {"ammonia_ppm_max": 25}
            $t->timestamp('last_seen_at')->nullable();
            $t->string('notes', 300)->nullable();
            $t->timestamps();
            $t->unique(['org_id', 'code']);
        });

        Schema::create('device_keys', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('device_id')->constrained('devices');
            $t->string('key_hash', 64)->unique(); // sha256 of the key; the key itself is shown once and never stored
            $t->string('hint', 8);
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });

        Schema::create('sensor_readings', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('device_id')->constrained('devices');
            $t->uuid('reading_id'); // made by the device: a retry with the same id is ignored
            $t->timestamp('read_at'); // same convention as every other table: the app's time zone
            $t->string('metric', 40);
            $t->double('value')->nullable();
            $t->string('unit', 16)->nullable();
            $t->string('rfid_epc', 64)->nullable();
            $t->foreignUuid('animal_id')->nullable()->constrained('animals');
            $t->jsonb('payload')->nullable();
            $t->string('result', 60)->nullable(); // what the reading made: weighing, presence, alert, stored
            $t->timestamp('created_at')->nullable(); // set by the app, in the app's time zone
            $t->unique(['org_id', 'device_id', 'reading_id']);
            $t->index(['device_id', 'read_at']);
            $t->index(['metric', 'read_at']);
        });

        Schema::create('sensor_alerts', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('device_id')->nullable()->constrained('devices');
            $t->foreignId('reading_id')->nullable()->constrained('sensor_readings');
            $t->foreignUuid('animal_id')->nullable()->constrained('animals');
            $t->foreignId('pen_id')->nullable()->constrained('pens');
            $t->string('kind', 30); // weight_loss | headcount | environment | out_too_long | unknown_tag | device_offline
            $t->string('subject', 64)->nullable(); // what it is about (metric, tag, goat, pen): one open alert per device, kind and subject an hour
            $t->string('severity', 10); // critical | warning | info
            $t->string('title', 160);
            $t->string('detail', 500)->nullable();
            $t->string('status', 14)->default('open'); // open | acknowledged | resolved
            $t->foreignId('task_id')->nullable()->constrained('tasks');
            $t->string('acknowledged_by')->nullable();
            $t->string('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'kind']);
        });

        Schema::create('animal_presence', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignUuid('animal_id')->constrained('animals');
            $t->foreignId('area_id')->nullable()->constrained('pens'); // the open area
            $t->string('direction', 3); // out | in
            $t->timestamp('at');
            $t->foreignId('device_id')->nullable()->constrained('devices');
            $t->string('source', 10); // manual | rfid | camera | sensor
            $t->string('recorded_by');
            $t->timestamp('created_at')->nullable(); // set by the app, in the app's time zone
            $t->index(['animal_id', 'at']);
        });

        Schema::table('weight_records', function (Blueprint $t) {
            $t->string('source', 10)->default('manual'); // manual | sensor | import
            $t->foreignId('device_id')->nullable()->constrained('devices');
        });

        foreach (['devices', 'device_keys', 'sensor_readings', 'sensor_alerts', 'animal_presence'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }

        DB::unprepared(<<<'SQL'
            -- A device is not signed in as a user, so its farm is found from its key before the tenant is set.
            -- Only this lookup crosses tenants, and it returns nothing but the farm and device of a valid, unrevoked key.
            CREATE OR REPLACE FUNCTION device_for_key(p_hash text) RETURNS TABLE(org_id uuid, device_id bigint)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT k.org_id, k.device_id FROM device_keys k WHERE k.key_hash = p_hash AND k.revoked_at IS NULL LIMIT 1
            $$;
            REVOKE ALL ON FUNCTION device_for_key(text) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION device_for_key(text) TO kkfarm_app;

            CREATE OR REPLACE FUNCTION sensor_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- The one allowed change: writing what a reading made (result), once, right after it is stored.
                IF TG_OP = 'UPDATE' AND TG_TABLE_NAME = 'sensor_readings' AND OLD.result IS NULL AND (to_jsonb(NEW) - 'result') = (to_jsonb(OLD) - 'result') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'Sensor readings and presence are append-only.';
            END $$;
            CREATE TRIGGER sensor_readings_append_only BEFORE UPDATE OR DELETE ON sensor_readings FOR EACH ROW EXECUTE FUNCTION sensor_append_only();
            CREATE TRIGGER animal_presence_append_only BEFORE UPDATE OR DELETE ON animal_presence FOR EACH ROW EXECUTE FUNCTION sensor_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sensor_readings_append_only ON sensor_readings; DROP TRIGGER IF EXISTS animal_presence_append_only ON animal_presence; DROP FUNCTION IF EXISTS device_for_key(text);');
        Schema::table('weight_records', function (Blueprint $t) {
            $t->dropConstrainedForeignId('device_id');
            $t->dropColumn('source');
        });
        Schema::drop('animal_presence');
        Schema::drop('sensor_alerts');
        Schema::drop('sensor_readings');
        Schema::drop('device_keys');
        Schema::drop('devices');
        DB::unprepared('DROP FUNCTION IF EXISTS sensor_append_only();');
        Schema::table('pens', fn (Blueprint $t) => $t->dropColumn('kind'));
    }
};
