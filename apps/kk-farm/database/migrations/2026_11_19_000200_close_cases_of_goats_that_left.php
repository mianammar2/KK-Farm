<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-347: cases still open on goats that had already left the farm (sold before the rule existed) are closed the same way
 * the rule now does it: on the exit date, Deceased if the goat died, with a note. Open health reports of those goats are closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('health_cases as c')->join('animals as a', 'a.id', '=', 'c.animal_id')
            ->whereIn('c.status', ['open', 'under_treatment', 'monitoring'])->where('a.status', '!=', 'active')
            ->get(['c.id', 'c.notes', 'c.resolved_on', 'a.status', 'a.exit_reason', 'a.exit_date']);
        foreach ($rows as $r) {
            $on = $r->exit_date ? date('d/m/Y', strtotime($r->exit_date)) : date('d/m/Y');
            $note = 'Closed: the goat left the farm ('.($r->exit_reason ?: $r->status).") on {$on}.";
            DB::table('health_cases')->where('id', $r->id)->update([
                'status' => $r->status === 'deceased' ? 'deceased' : 'closed',
                'resolved_on' => $r->resolved_on ?? ($r->exit_date ?: now()->toDateString()),
                'notes' => trim(($r->notes ? $r->notes."\n" : '').$note),
                'updated_at' => now(),
            ]);
        }
        DB::table('health_records')->where('status', 'open')
            ->whereIn('animal_id', DB::table('animals')->where('status', '!=', 'active')->select('id'))->update(['status' => 'closed']);
    }

    public function down(): void
    {
        // a data correction: the cases stay closed (their notes say why)
    }
};
