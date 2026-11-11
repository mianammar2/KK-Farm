<?php

use App\Support\AnimalCode;
use App\Support\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-208: BR-001 permanent Animal ID series (KKF-000125: never changed, never reused, separate from the descriptive tag
 * and the RFID EPC) and BR-018 weight review (impossible, repeated or implausible scale readings are held for review).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->string('animal_id_prefix', 6)->nullable();
            $t->unsignedInteger('animal_seq')->default(0); // last number given; only ever goes up
        });
        Schema::table('animals', fn (Blueprint $t) => $t->string('animal_code', 16)->nullable());
        DB::statement('CREATE UNIQUE INDEX animals_org_code_unique ON animals (org_id, animal_code)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION animals_code_immutable() RETURNS trigger AS $$
            BEGIN
                IF OLD.animal_code IS NOT NULL AND NEW.animal_code IS DISTINCT FROM OLD.animal_code THEN
                    RAISE EXCEPTION 'The permanent Animal ID % cannot be changed', OLD.animal_code;
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;
            CREATE TRIGGER animals_code_immutable BEFORE UPDATE OF animal_code ON animals FOR EACH ROW EXECUTE FUNCTION animals_code_immutable();
        SQL);
        Schema::table('weight_records', function (Blueprint $t) {
            $t->string('flag', 12)->nullable();          // impossible | duplicate | conflict | jump | drop
            $t->string('flag_note', 200)->nullable();
            $t->string('review_status', 10)->nullable(); // held | accepted | rejected (null: normal reading)
            $t->string('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note', 300)->nullable();
        });

        // Number existing goats in the order they were registered, per farm business.
        foreach (DB::table('organizations')->get(['id', 'name']) as $org) {
            Tenant::enter($org->id);
            try {
                $prefix = AnimalCode::prefixFor($org->name);
                $n = 0;
                foreach (DB::table('animals')->where('org_id', $org->id)->orderBy('created_at')->orderBy('id')->pluck('id') as $id) {
                    DB::table('animals')->where('id', $id)->update(['animal_code' => AnimalCode::format($prefix, ++$n)]);
                }
                DB::table('organizations')->where('id', $org->id)->update(['animal_id_prefix' => $prefix, 'animal_seq' => $n]);
            } finally {
                Tenant::leave();
            }
        }
    }

    public function down(): void
    {
        Schema::table('weight_records', fn (Blueprint $t) => $t->dropColumn(['flag', 'flag_note', 'review_status', 'reviewed_by', 'reviewed_at', 'review_note']));
        DB::unprepared('DROP TRIGGER IF EXISTS animals_code_immutable ON animals; DROP FUNCTION IF EXISTS animals_code_immutable();');
        DB::statement('DROP INDEX IF EXISTS animals_org_code_unique');
        Schema::table('animals', fn (Blueprint $t) => $t->dropColumn('animal_code'));
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['animal_id_prefix', 'animal_seq']));
    }
};
