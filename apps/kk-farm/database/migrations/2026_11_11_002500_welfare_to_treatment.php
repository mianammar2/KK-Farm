<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-199: journey J Welfare-to-Treatment. A welfare case links to the vet's health case (diagnosis), records a repeat
 * of the same problem, holds routine handling of the goat until the vet clears it, and assigns care actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('welfare_cases', function (Blueprint $t) {
            $t->foreignId('health_case_id')->nullable()->constrained('health_cases');
            $t->foreignId('recurrence_of_id')->nullable()->constrained('welfare_cases');
            $t->string('handling_cleared_by')->nullable();
            $t->timestamp('handling_cleared_at')->nullable();
            $t->string('handling_note', 300)->nullable();
        });
        Schema::table('welfare_actions', function (Blueprint $t) {
            $t->foreignId('assigned_user_id')->nullable()->constrained('users');
            $t->date('due_on')->nullable();
            $t->foreignId('task_id')->nullable()->constrained('tasks');
            $t->foreignId('pen_id')->nullable()->constrained('pens');
        });
        Schema::table('tasks', function (Blueprint $t) {
            $t->foreignId('welfare_hold_case_id')->nullable()->constrained('welfare_cases'); // routine task held for humane handling
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $t) => $t->dropConstrainedForeignId('welfare_hold_case_id'));
        Schema::table('welfare_actions', function (Blueprint $t) {
            foreach (['assigned_user_id', 'task_id', 'pen_id'] as $c) {
                $t->dropConstrainedForeignId($c);
            }
            $t->dropColumn('due_on');
        });
        Schema::table('welfare_cases', function (Blueprint $t) {
            $t->dropConstrainedForeignId('health_case_id');
            $t->dropConstrainedForeignId('recurrence_of_id');
            $t->dropColumn(['handling_cleared_by', 'handling_cleared_at', 'handling_note']);
        });
    }
};
