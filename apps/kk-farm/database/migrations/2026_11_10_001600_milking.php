<?php

use App\Support\FieldCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * D-117: Milking. A doe that kids starts a lactation by herself (she is "in milk"); milk is recorded per doe, day and
 * session as append-only rows (a correction supersedes, nothing is deleted); a doe under a milk withdrawal still has
 * her milk recorded, marked discarded. Drying off closes the lactation. A doe bought in milk starts one on arrival.
 */
return new class extends Migration
{
    private const NEW_FIELDS = ['milking_at_arrival' => ['pregnancy_days', 1], 'days_in_milk' => ['pregnancy_days', 2]];

    public function up(): void
    {
        Schema::create('lactations', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignUuid('animal_id')->constrained('animals');
            $t->foreignId('kidding_id')->nullable()->constrained();
            $t->unsignedSmallInteger('number'); // 1st, 2nd ... lactation of this doe
            $t->date('started_on');
            $t->string('source', 20); // kidding | arrival
            $t->date('dried_on')->nullable();
            $t->string('dry_reason', 120)->nullable();
            $t->string('dried_by')->nullable();
            $t->string('recorded_by');
            $t->timestamps();
            $t->index(['animal_id', 'dried_on']);
        });
        DB::statement('CREATE UNIQUE INDEX lactations_one_open ON lactations (animal_id) WHERE dried_on IS NULL');

        Schema::create('milk_records', function (Blueprint $t) {
            $t->uuid('id')->primary(); // client-generated: a resubmitted entry is harmless
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignUuid('animal_id')->constrained('animals');
            $t->foreignId('lactation_id')->constrained();
            $t->date('milked_on');
            $t->string('session', 10); // morning | midday | evening
            $t->decimal('litres', 6, 2);
            $t->boolean('discarded')->default(false);
            $t->string('discard_reason', 120)->nullable();
            $t->uuid('supersedes_id')->nullable();
            $t->string('source', 10)->default('manual');
            $t->string('recorded_by');
            $t->timestamps();
            $t->index(['animal_id', 'milked_on']);
            $t->index(['milked_on', 'session']);
        });

        foreach (['lactations', 'milk_records'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }

        Schema::table('organizations', function (Blueprint $t) {
            $t->decimal('high_yield_litres', 4, 2)->nullable(); // 7-day average L/day from which a doe is high-producing
            $t->unsignedSmallInteger('dry_off_days')->nullable(); // remind to dry off after this many days in milk
        });

        // The High-Producing Lactating standard is now matched by milk yield.
        DB::table('nutrition_standards')->whereNull('category')->where('name', 'High-Producing Lactating Female')->update(['category' => 'high_lactating']);

        // Register: "In milk when bought?" and days since she kidded, for farms that already have their fields.
        $defaults = collect(FieldCatalog::defaults())->keyBy('key');
        $options = FieldCatalog::options();
        foreach (DB::table('organizations')->pluck('id') as $org) {
            foreach (self::NEW_FIELDS as $key => [$after, $offset]) {
                $d = $defaults[$key];
                $base = (int) (DB::table('field_definitions')->where('org_id', $org)->where('key', $after)->value('sort') ?? 900);
                DB::table('field_definitions')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'org_id' => $org, 'entity' => 'animal', 'key' => $key, 'label' => $d['label'], 'type' => $d['type'],
                    'options' => isset($options[$key]) ? json_encode(collect($options[$key])->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values()) : null,
                    'section' => $d['section'], 'sort' => $base + $offset, 'required' => false, 'builtin' => true, 'active' => true,
                    'help' => $d['help'] ?? null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Does already lactating get an open lactation from their latest kidding.
        foreach (DB::table('animals')->where('status', 'active')->where('sex', 'female')->whereIn('repro_status', ['lactating', 'nursing', 'kidded'])->get() as $doe) {
            $k = DB::table('kiddings')->where('dam_id', $doe->id)->orderByDesc('kidded_on')->first();
            DB::table('lactations')->insert([
                'org_id' => $doe->org_id, 'animal_id' => $doe->id, 'kidding_id' => $k?->id, 'number' => DB::table('kiddings')->where('dam_id', $doe->id)->count() ?: 1,
                'started_on' => $k?->kidded_on ?? substr((string) $doe->created_at, 0, 10), 'source' => $k ? 'kidding' : 'arrival', 'recorded_by' => 'System',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('field_definitions')->whereIn('key', array_keys(self::NEW_FIELDS))->where('builtin', true)->delete();
        DB::table('nutrition_standards')->where('category', 'high_lactating')->update(['category' => null]);
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['high_yield_litres', 'dry_off_days']));
        Schema::drop('milk_records');
        Schema::drop('lactations');
    }
};
