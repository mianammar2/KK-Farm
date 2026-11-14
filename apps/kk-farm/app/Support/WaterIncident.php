<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\Asset;
use App\Models\Department;
use App\Models\EnvironmentIssue;
use App\Models\EnvironmentRound;
use App\Models\LedgerEntry;
use App\Models\MaintenanceJob;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Models\WaterPoint;
use App\Models\WaterTest;
use App\Models\WelfareCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * D-198: journey I Water-to-Animal Health (V3 flow 32). Environment → Maintenance → Welfare → Vet → Finance:
 * a water interruption, shortage, contamination or failed inspection opens one critical incident per shed with owned
 * tasks, a repair job on each linked pump / tank, and a welfare case for the goats exposed (welfare comes first, BR-021).
 * An emergency supply is recorded with its cost and must be approved as safe by someone else; supply is restored
 * (contamination needs a safe lab test after the incident started); the vet checks the goats; another supervisor
 * verifies, which closes the welfare case. Outage minutes run from start to restoration.
 */
final class WaterIncident
{
    /** @param list<int> $pointIds */
    public static function open(Shed $shed, string $problem, array $pointIds, string $text, User $by, ?EnvironmentRound $round = null, ?WaterTest $test = null): EnvironmentIssue
    {
        return DB::transaction(function () use ($shed, $problem, $pointIds, $text, $by, $round, $test) {
            $existing = EnvironmentIssue::where('shed_id', $shed->id)->where('kind', 'water')->where('status', 'open')->lockForUpdate()->first();
            if ($existing) { // one open incident per shed: widen it instead of a second alert
                $existing->update(['point_ids' => array_values(array_unique(array_merge($existing->point_ids ?? [], $pointIds))), 'water_test_id' => $existing->water_test_id ?? $test?->id,
                    'problem' => $problem === 'contamination' ? 'contamination' : $existing->problem, 'description' => mb_substr($existing->description.' · '.$text, 0, 1000)]);
                self::repairJobs($existing, $pointIds, $by);

                return $existing;
            }
            $points = WaterPoint::whereIn('id', $pointIds)->get();
            $pens = $points->isNotEmpty() && $points->every(fn ($p) => $p->pen_id) ? $points->pluck('pen_id')->unique()->all() : Pen::where('shed_id', $shed->id)->pluck('id')->all();
            $exposed = Animal::active()->whereIn('pen_id', $pens)->count();
            $label = EnvironmentIssue::PROBLEMS[$problem];
            $task = null;
            foreach (['Management', 'Veterinary'] as $dept) {
                $task = Task::create(['title' => mb_substr("Water {$label}: {$shed->name}", 0, 190), 'type' => $dept === 'Veterinary' ? 'health' : 'maintenance', 'priority' => 'critical', 'status' => 'open',
                    'due_on' => now()->toDateString(), 'department_id' => Department::where('name', $dept)->value('id'),
                    'notes' => mb_substr($dept === 'Veterinary' ? "Check the {$exposed} goats for dehydration or water-borne illness. {$text}" : "Give an approved safe supply now, then repair. {$text}", 0, 500)]);
            }
            if ($exposed) {
                DB::statement('LOCK TABLE welfare_cases IN SHARE ROW EXCLUSIVE MODE');
            }
            $welfare = $exposed ? WelfareCase::create(['case_no' => (int) WelfareCase::max('case_no') + 1, 'pen_id' => count($pens) === 1 ? $pens[0] : null, 'opened_on' => now()->toDateString(),
                'issue' => 'feed_water', 'severity' => 'critical', 'status' => 'action', 'triage' => mb_substr("{$label} in {$shed->name}: {$exposed} goats without safe water. {$text}", 0, 1000), 'opened_by' => $by->name]) : null;
            $issue = EnvironmentIssue::create(['round_id' => $round?->id, 'shed_id' => $shed->id, 'kind' => 'water', 'severity' => 'critical', 'description' => mb_substr("{$label}. {$text}", 0, 1000),
                'task_id' => $task?->id, 'problem' => $problem, 'point_ids' => array_values($pointIds), 'started_at' => now(), 'welfare_case_id' => $welfare?->id,
                'goats_exposed' => $exposed, 'water_test_id' => $test?->id]);
            self::repairJobs($issue, $pointIds, $by);

            return $issue;
        });
    }

    /** A repair job on each pump / tank asset behind the affected points (contamination: cleaning and disinfection). */
    private static function repairJobs(EnvironmentIssue $issue, array $pointIds, User $by): void
    {
        foreach (WaterPoint::whereIn('id', $pointIds)->whereNotNull('asset_id')->get() as $wp) {
            if (MaintenanceJob::where('environment_issue_id', $issue->id)->where('asset_id', $wp->asset_id)->exists()) {
                continue;
            }
            MaintenanceJob::create(['asset_id' => $wp->asset_id, 'kind' => 'repair', 'requested_on' => now()->toDateString(), 'priority' => 'critical', 'requested_by' => $by->name, 'environment_issue_id' => $issue->id,
                'problem' => $issue->problem === 'contamination' ? "Water point {$wp->name}: unsafe water, clean and disinfect" : "Water point {$wp->name}: no or unsafe water"]);
            Asset::whereKey($wp->asset_id)->update(['status' => 'under_repair']);
        }
    }

    public static function supply(EnvironmentIssue $issue, string $source, ?float $cost, User $by): void
    {
        self::assertOpen($issue);
        abort_if($issue->supply_at !== null, 422, 'The emergency supply is already recorded.');
        DB::transaction(function () use ($issue, $source, $cost, $by) {
            $minor = $cost ? (int) round($cost * 100) : null;
            $ledger = $minor ? LedgerEntry::create(['direction' => 'out', 'category' => 'Water Emergency', 'amount' => $minor / 100, 'occurred_on' => now()->toDateString(),
                'description' => EnvironmentIssue::SOURCES[$source]." · {$issue->shed?->name}", 'recorded_by' => $by->name, 'payment_status' => 'pending']) : null;
            $issue->update(['supply_source' => $source, 'supply_at' => now(), 'supply_by' => $by->name, 'supply_cost_minor' => $minor, 'supply_ledger_id' => $ledger?->id]);
        });
    }

    /** The emergency supply is approved as safe to drink by the owner, a manager or the vet, not by whoever arranged it. */
    public static function approveSupply(EnvironmentIssue $issue, User $by): void
    {
        self::assertOpen($issue);
        abort_unless($by->passes('approve_water_safe'), 403, 'The owner, manager or vet approves the water as safe.');
        abort_if($issue->supply_at === null, 422, 'Record the emergency supply first.');
        abort_if($issue->supply_approved_at !== null, 422, 'Already approved.');
        if ($issue->supply_by === $by->name && ! $by->passes('override_separation')) {
            throw ValidationException::withMessages(['supply' => 'Someone other than the person who arranged the supply approves it.']);
        }
        $issue->update(['supply_approved_by' => $by->name, 'supply_approved_at' => now()]);
    }

    /** Normal supply back. Contamination needs a safe lab test of the affected points taken after the incident started. */
    public static function restore(EnvironmentIssue $issue): void
    {
        if ($issue->restored_at) {
            return;
        }
        if ($issue->problem === 'contamination') {
            $points = $issue->point_ids ?: [];
            $since = $issue->started_at->toDateString();
            $unsafe = collect($points)->filter(fn ($id) => WaterTest::where('water_point_id', $id)->where('sampled_on', '>=', $since)->where('verdict', 'safe')
                ->where('id', '!=', (int) $issue->water_test_id)->doesntExist());
            if ($unsafe->isNotEmpty()) {
                throw ValidationException::withMessages(['resolution' => 'Contaminated water: record a safe lab test of '.WaterPoint::whereIn('id', $unsafe)->pluck('name')->implode(', ').' before restoring supply.']);
            }
        }
        $issue->update(['restored_at' => now()]);
    }

    /** The vet's check of the goats exposed: how many fell ill and what was done (records; the vet decides treatment). */
    public static function healthCheck(EnvironmentIssue $issue, int $ill, string $outcome, User $by): void
    {
        abort_unless($by->passes('clinical'), 403, 'The vet records the health check.');
        abort_if($issue->status === 'verified', 422, 'This incident is closed.');
        $issue->update(['goats_ill' => $ill, 'health_outcome' => $outcome, 'health_checked_by' => $by->name, 'health_checked_at' => now()]);
        if ($issue->welfareCase && $issue->welfareCase->status !== 'closed') {
            $issue->welfareCase->update(['status' => 'follow_up']);
        }
    }

    /** Before a water incident can be verified: restored, the vet's health check, and an approved supply if one was used. */
    public static function verifyBlockers(EnvironmentIssue $issue): array
    {
        return array_values(array_filter([
            $issue->restored_at ? null : 'supply not restored',
            $issue->health_checked_at ? null : 'no vet health check of the goats',
            $issue->supply_at && ! $issue->supply_approved_at ? 'emergency supply not approved as safe' : null,
            $issue->jobs()->where('status', '!=', 'done')->exists() ? 'repair job still open' : null,
        ]));
    }

    public static function closeWelfare(EnvironmentIssue $issue, User $by): void
    {
        $issue->welfareCase?->update(['status' => 'closed', 'closed_by' => $by->name, 'closed_at' => now(),
            'closure_evidence' => mb_substr("Water incident verified. Restored {$issue->restored_at?->format('d/m g:i A')}; vet check by {$issue->health_checked_by}: {$issue->goats_ill} ill. {$issue->health_outcome}", 0, 1000)]);
    }

    public static function outageMinutes(EnvironmentIssue $i): ?int
    {
        return $i->started_at ? (int) $i->started_at->diffInMinutes($i->restored_at ?? now()) : null;
    }

    public static function cost(EnvironmentIssue $i): int
    {
        return (int) $i->supply_cost_minor + (int) $i->jobs()->sum('cost_minor') + (int) ($i->water_test_id ? WaterTest::whereKey($i->water_test_id)->value('cost_minor') : 0);
    }

    /** @return array{outageMinutes: int, incidents: int, verifiedPct: ?int, cost: int, toSupply: ?int} last 30 days */
    public static function kpis(): array
    {
        $rows = EnvironmentIssue::where('kind', 'water')->where('created_at', '>=', now()->subDays(30))->get();
        $done = $rows->whereIn('status', ['resolved', 'verified']);
        $toSupply = $rows->filter(fn ($i) => $i->started_at && $i->supply_at)->map(fn ($i) => $i->started_at->diffInMinutes($i->supply_at));

        return ['outageMinutes' => (int) $rows->sum(fn ($i) => self::outageMinutes($i) ?? 0), 'incidents' => $rows->count(),
            'verifiedPct' => $done->count() ? (int) round($done->where('status', 'verified')->count() / $done->count() * 100) : null,
            'cost' => (int) round($rows->sum(fn ($i) => self::cost($i)) / 100), 'toSupply' => $toSupply->count() ? (int) round($toSupply->avg()) : null];
    }

    private static function assertOpen(EnvironmentIssue $issue): void
    {
        abort_unless($issue->kind === 'water' && $issue->status === 'open', 422, 'Only an open water incident.');
    }
}
