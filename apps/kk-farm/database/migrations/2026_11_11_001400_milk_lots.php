<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-188: App 17 Dairy & Milk (V3 flow 17): a closed milking session (hygiene / equipment / operator and udder checks,
 * kid and colostrum allocation, mass balance), the saleable milk lot it creates (MLK-YYMMDD-NNN) with QA, chilling,
 * release, hold, recall, and dispatches to buyers.
 */
return new class extends Migration
{
    private const TABLES = ['milk_sessions', 'milk_lots', 'milk_dispatches'];

    public function up(): void
    {
        Schema::create('milk_sessions', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->date('milked_on');
            $t->string('session', 10); // morning | evening
            $t->boolean('hygiene_ok');
            $t->boolean('equipment_ok');
            $t->string('operator', 80);
            $t->decimal('produced_l', 8, 2);
            $t->decimal('held_l', 8, 2);       // withdrawal / mastitis: vet review, never saleable (BR-010)
            $t->decimal('colostrum_l', 8, 2);  // never saleable (BR-009)
            $t->decimal('kid_l', 8, 2);
            $t->decimal('discard_l', 8, 2)->default(0);
            $t->decimal('saleable_l', 8, 2);
            $t->string('notes', 500)->nullable();
            $t->string('closed_by');
            $t->timestamps();
            $t->unique(['org_id', 'milked_on', 'session']);
        });
        Schema::create('milk_lots', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('code', 20);
            $t->foreignId('session_id')->constrained('milk_sessions');
            $t->decimal('litres', 8, 2);
            $t->string('status', 12)->default('pending_qc'); // pending_qc | stored | released | hold | rejected | dispatched | recalled
            $t->string('tank', 40)->nullable();
            $t->decimal('storage_temp_c', 4, 1)->nullable();
            $t->boolean('appearance_ok')->nullable();
            $t->boolean('smell_ok')->nullable();
            $t->string('residue', 12)->nullable(); // negative | positive | not_tested
            $t->string('tested_by')->nullable();
            $t->timestamp('tested_at')->nullable();
            $t->string('released_by')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->string('hold_reason', 500)->nullable();
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['org_id', 'code']);
        });
        Schema::create('milk_dispatches', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('lot_id')->constrained('milk_lots');
            $t->date('dispatched_on');
            $t->string('kind', 12); // sale | processing
            $t->foreignId('customer_id')->nullable()->constrained('customers');
            $t->string('buyer', 120)->nullable();
            $t->decimal('litres', 8, 2);
            $t->bigInteger('price_per_litre_minor')->nullable();
            $t->foreignId('sale_id')->nullable()->constrained('sales');
            $t->string('recorded_by');
            $t->timestamps();
        });
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
        Schema::table('milk_records', fn (Blueprint $t) => $t->foreignId('session_id')->nullable()->constrained('milk_sessions'));
    }

    public function down(): void
    {
        Schema::table('milk_records', fn (Blueprint $t) => $t->dropConstrainedForeignId('session_id'));
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::drop($table);
        }
    }
};
