<?php

namespace App\Support;

use App\Models\Department;
use App\Models\HealthRecord;
use App\Models\ParasiteSample;
use App\Models\Task;
use App\Models\Treatment;
use App\Models\WelfareCase;
use Illuminate\Support\Facades\DB;

/**
 * D-200: journey K Parasite Surveillance-to-Control (V3 flow 34). Veterinary → Inventory → Welfare → Analytics:
 * test, then dose only the goats the vet selects, with the vet's withdrawal and retest date, never blind repeat dosing.
 * A retest gives the egg-count reduction; under 95 % is flagged as possible dewormer resistance for the vet.
 * FAMACHA 4–5 or body condition ≤ 2 opens a welfare case.
 */
final class Parasites
{
    public const RESISTANCE_BELOW = 95.0; // FECRT: a working dewormer cuts the egg count by at least 95 %

    /** The vet's guide (org settings): does this sample meet the treat threshold? Advisory only; the vet decides. */
    public static function aboveGuide(ParasiteSample $s): bool
    {
        $org = Tenant::org();

        return ($org?->fec_treat_epg && $s->epg !== null && $s->epg >= $org->fec_treat_epg) || ($org?->famacha_treat && $s->famacha !== null && $s->famacha >= $org->famacha_treat);
    }

    /** A retest: the reduction against the sample it follows; under 95 % raises a resistance alert for the vet. */
    public static function scoreRetest(ParasiteSample $s): void
    {
        $pre = $s->retestOf;
        if (! $pre || ! $pre->epg || $s->epg === null) {
            return;
        }
        $pct = round(($pre->epg - $s->epg) / $pre->epg * 100, 1);
        $flag = $pre->decision === 'treat' && $pct < self::RESISTANCE_BELOW;
        $s->update(['reduction_pct' => $pct, 'resistance_flag' => $flag]);
        if ($flag) {
            Task::create(['title' => mb_substr("Possible dewormer resistance: {$s->label()} ".($s->animal?->farm_number ?? $s->pen?->fullName() ?? ''), 0, 190), 'type' => 'health', 'priority' => 'high', 'status' => 'open',
                'due_on' => now()->toDateString(), 'animal_id' => $s->animal_id, 'pen_id' => $s->pen_id, 'department_id' => Department::where('name', 'Veterinary')->value('id'),
                'notes' => "Egg count fell only {$pct}% ({$pre->epg} → {$s->epg} EPG) after the dose. Review the product and the plan."]);
        }
    }

    /** Pale membranes (FAMACHA 4–5) or poor body condition: a welfare case for the goat. */
    public static function welfareFlag(ParasiteSample $s): ?WelfareCase
    {
        if (! $s->animal_id || ! (($s->famacha ?? 0) >= 4 || ($s->bcs !== null && $s->bcs <= 2))) {
            return null;
        }
        if (WelfareCase::where('animal_id', $s->animal_id)->where('issue', 'body_condition')->where('status', '!=', 'closed')->exists()) {
            return null;
        }
        DB::statement('LOCK TABLE welfare_cases IN SHARE ROW EXCLUSIVE MODE');

        return WelfareCase::create(['case_no' => (int) WelfareCase::max('case_no') + 1, 'animal_id' => $s->animal_id, 'pen_id' => $s->animal?->pen_id, 'opened_on' => now()->toDateString(),
            'issue' => 'body_condition', 'severity' => ($s->famacha ?? 0) >= 5 ? 'high' : 'medium', 'status' => 'open', 'opened_by' => $s->recorded_by,
            'triage' => "From parasite sample {$s->label()}: FAMACHA ".($s->famacha ?? '—').', BCS '.($s->bcs ?? '—').($s->epg !== null ? ", {$s->epg} EPG" : '').'.']);
    }

    /** @return array<string, mixed> KPIs: FEC trend by month, targeted treatment rate, blind doses, resistance alerts */
    public static function kpis(): array
    {
        $since = now()->subDays(90)->toDateString();
        $trend = ParasiteSample::whereNotNull('epg')->where('sampled_on', '>=', now()->subMonths(6)->startOfMonth()->toDateString())->get()
            ->groupBy(fn ($s) => $s->sampled_on->format('Y-m'))->sortKeys()
            ->map(fn ($g, $m) => ['month' => \Illuminate\Support\Carbon::parse("{$m}-01")->format('M Y'), 'epg' => (int) round($g->avg('epg')), 'samples' => $g->count()])->values();
        $targeted = Treatment::whereNotNull('parasite_sample_id')->where('given_on', '>=', $since)->count();
        $blind = HealthRecord::where('kind', 'deworming')->where('care_status', 'administered')->where('occurred_on', '>=', $since)->count();
        $tested = ParasiteSample::where('sampled_on', '>=', $since)->whereNotNull('decision')->get();

        return [
            'trend' => $trend,
            'targetedRate' => ($targeted + $blind) ? (int) round($targeted / ($targeted + $blind) * 100) : null, // doses given after a test
            'targeted' => $targeted, 'blind' => $blind,
            'treatedShare' => $tested->count() ? (int) round($tested->where('decision', 'treat')->count() / $tested->count() * 100) : null,
            'resistance' => ParasiteSample::where('resistance_flag', true)->where('sampled_on', '>=', now()->subYear()->toDateString())->count(),
            'awaiting' => ParasiteSample::whereNull('decision')->count(),
            'retestsDue' => ParasiteSample::where('decision', 'treat')->whereNotNull('retest_due_on')->where('retest_due_on', '<=', now()->toDateString())
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('parasite_samples as r')->whereColumn('r.retest_of_id', 'parasite_samples.id'))->count(),
        ];
    }
}
