<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-078: the last workbook gaps.
 * - Expenses: payment status (Paid/Pending/Partial), paid by, related reference.
 * - Tasks: related goat, workbook types, Low/Medium/High/Critical priority, Open/In Progress/Blocked/Completed/Cancelled.
 * - Weight log: body condition score 1–5.
 * - Preventive care: batch/lot, cost, Administered/Planned/Cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $t) {
            $t->string('payment_status', 10)->default('paid'); // paid | pending | partial
            $t->string('paid_by', 80)->nullable();
            $t->string('reference', 60)->nullable();
        });
        Schema::table('tasks', function (Blueprint $t) {
            $t->foreignUuid('animal_id')->nullable()->constrained('animals');
            $t->string('notes', 500)->nullable();
        });
        // Workbook vocabulary: the old two-step priority and done/open status map onto the new lists.
        DB::table('tasks')->where('priority', 'normal')->update(['priority' => 'medium']);
        DB::table('tasks')->where('status', 'done')->update(['status' => 'completed']);
        Schema::table('weight_records', fn (Blueprint $t) => $t->unsignedTinyInteger('body_score')->nullable());
        Schema::table('health_records', function (Blueprint $t) {
            $t->string('batch_lot', 40)->nullable();
            $t->unsignedBigInteger('cost_minor')->nullable();
            $t->string('care_status', 14)->nullable(); // administered | planned | cancelled (preventive care)
        });
    }

    public function down(): void
    {
        Schema::table('health_records', fn (Blueprint $t) => $t->dropColumn(['batch_lot', 'cost_minor', 'care_status']));
        Schema::table('weight_records', fn (Blueprint $t) => $t->dropColumn('body_score'));
        DB::table('tasks')->where('status', 'completed')->update(['status' => 'done']);
        DB::table('tasks')->where('status', '<>', 'done')->update(['status' => 'open']);
        DB::table('tasks')->where('priority', '<>', 'high')->update(['priority' => 'normal']);
        Schema::table('tasks', function (Blueprint $t) {
            $t->dropConstrainedForeignId('animal_id');
            $t->dropColumn('notes');
        });
        Schema::table('ledger_entries', fn (Blueprint $t) => $t->dropColumn(['payment_status', 'paid_by', 'reference']));
    }
};
