<?php

use App\Models\HealthCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-174: the farm's disease master (Veterinary → Configuration), seeded from the built-in condition list. */
return new class extends Migration
{
    public const CATEGORY = [
        'Pneumonia' => 'respiratory', 'Diarrhoea' => 'digestive', 'Bloat' => 'digestive', 'Fever' => 'general', 'Worms / internal parasites' => 'parasitic',
        'Ticks / mites / lice' => 'parasitic', 'Foot rot / lameness' => 'infectious', 'Mastitis' => 'reproductive', 'Eye infection' => 'infectious', 'Skin disease' => 'infectious',
        'PPR (Peste des petits ruminants)' => 'infectious', 'Enterotoxaemia' => 'infectious', 'Abortion' => 'reproductive', 'Retained placenta' => 'reproductive',
        'Weak kid' => 'neonatal', 'Poisoning' => 'toxic', 'Off feed' => 'general', 'Wound / injury' => 'injury', 'Fracture' => 'injury',
    ];

    public function up(): void
    {
        Schema::create('diseases', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('name', 120);
            $t->string('category', 20)->default('general');
            $t->boolean('zoonotic')->default(false);   // can spread to people
            $t->boolean('notifiable')->default(false); // must be reported to the livestock department
            $t->string('notes', 500)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['org_id', 'name']);
        });
        DB::statement('ALTER TABLE diseases ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE diseases FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON diseases USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE diseases ADD CONSTRAINT diseases_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)');

        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            foreach (HealthCase::CONDITIONS as $name) {
                DB::table('diseases')->insert([
                    'org_id' => $orgId, 'name' => $name, 'category' => self::CATEGORY[$name] ?? 'general',
                    'notifiable' => str_starts_with($name, 'PPR'), 'zoonotic' => $name === 'Abortion', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::drop('diseases');
    }
};
