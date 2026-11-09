<?php

use App\Support\FieldCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * D-091: teeth for aging, an arrival check (temperature, body condition), pregnant when bought, editing a goat after
 * registering, and removing a wrong goat record in two steps (asked, then confirmed) with a trail, never a hard delete.
 */
return new class extends Migration
{
    /** key => [after key, sort offset] for farms that already have their fields. */
    private const NEW_FIELDS = ['teeth' => ['birth_date', -5], 'body_score' => ['health_flag', 1], 'temperature_f' => ['health_flag', 2], 'pregnant_at_arrival' => ['repro_status', 1], 'pregnancy_months' => ['repro_status', 2]];

    public function up(): void
    {
        Schema::table('animals', function (Blueprint $t) {
            $t->string('teeth', 8)->nullable(); // milk | 2 | 4 | 6 | 8 | worn
            $t->string('removal_requested_by')->nullable();
            $t->timestamp('removal_requested_at')->nullable();
            $t->string('removal_reason', 300)->nullable();
            $t->string('voided_by')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->string('status_before_void', 20)->nullable(); // restored to this
        });

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
    }

    public function down(): void
    {
        DB::table('field_definitions')->whereIn('key', array_keys(self::NEW_FIELDS))->where('builtin', true)->delete();
        Schema::table('animals', fn (Blueprint $t) => $t->dropColumn(['teeth', 'removal_requested_by', 'removal_requested_at', 'removal_reason', 'voided_by', 'voided_at', 'status_before_void']));
    }
};
