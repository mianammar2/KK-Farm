<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D-180: Farm Operations: shift checklists, the incident register and shift handovers. */
return new class extends Migration
{
    private const TABLES = ['checklist_templates', 'checklist_runs', 'incidents', 'shift_handovers'];

    public const DEFAULTS = [
        ['Morning Checklist', 'morning', ['Count the goats in every pen', 'Water troughs full and clean', 'Morning feed given', 'Check the sick and isolation pens', 'Clean feeders and remove leftovers', 'Report any goat off feed or limping']],
        ['Afternoon Checklist', 'afternoon', ['Midday feed given', 'Water refilled', 'Shade and ventilation working', 'Kids suckling and active', 'Pens dry: change wet bedding']],
        ['Evening Checklist', 'evening', ['Evening feed given', 'Headcount matches the morning', 'Gates and doors locked', 'Lights and water pumps checked', 'Write the shift handover']],
    ];

    public function up(): void
    {
        Schema::create('checklist_templates', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->string('name', 120);
            $t->string('shift', 12); // morning | afternoon | evening
            $t->json('items');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('checklist_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->foreignId('template_id')->constrained('checklist_templates');
            $t->date('run_on');
            $t->json('done');                       // indexes of the items ticked
            $t->string('completed_by')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->string('verified_by')->nullable();  // supervisor review
            $t->timestamp('verified_at')->nullable();
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['template_id', 'run_on']);
        });
        Schema::create('incidents', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->dateTime('occurred_at');
            $t->string('kind', 24);
            $t->string('severity', 10);
            $t->foreignId('pen_id')->nullable()->constrained('pens');
            $t->foreignUuid('animal_id')->nullable()->constrained('animals');
            $t->string('description', 1000);
            $t->string('reported_by');
            $t->string('status', 10)->default('open'); // open | resolved
            $t->string('resolution', 1000)->nullable();
            $t->string('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('shift_handovers', function (Blueprint $t) {
            $t->id();
            $t->uuid('org_id')->default(DB::raw('app_current_org()'));
            $t->date('handed_on');
            $t->string('shift', 12);
            $t->string('from_name');
            $t->string('to_name', 80)->nullable();
            $t->string('summary', 2000);
            $t->string('pending', 1000)->nullable();
            $t->timestamps();
        });
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_fk FOREIGN KEY (org_id) REFERENCES organizations(id)");
        }
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            foreach (self::DEFAULTS as [$name, $shift, $items]) {
                DB::table('checklist_templates')->insert(['org_id' => $orgId, 'name' => $name, 'shift' => $shift, 'items' => json_encode($items), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::drop($table);
        }
    }
};
