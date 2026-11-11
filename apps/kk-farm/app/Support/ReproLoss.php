<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\Department;
use App\Models\Kidding;
use App\Models\ReproLossCase;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * D-206: journey Q Reproductive Loss Investigation (V3 flow 40). Maternity → Veterinary → Housing → Quality → Breeding:
 * an abortion, early loss, stillbirth or abnormal kidding opens a case: isolate the doe (time to containment), the
 * aborted material is handled only by the vet or staff trained for it (gloves; some causes spread to people), samples go
 * to the lab, and only the vet diagnoses: nobody self-diagnoses. A suspected zoonotic cause restricts the pen through a
 * contagious vet case; the vet's breeding decision holds the doe from mating until a date or for good. Two or more
 * losses in a pen within 30 days raise a cluster alert.
 */
final class ReproLoss
{
    public const CLUSTER_DAYS = 30;

    public static function canHandle(User $u): bool
    {
        return $u->passes('clinical') || (bool) $u->biohazard_trained;
    }

    public static function open(Kidding $k, Animal $dam, ?string $sireId, string $by): ?ReproLossCase
    {
        $type = ReproLossCase::FROM_OUTCOMES[$k->outcome] ?? null;
        if (! $type) {
            return null;
        }
        DB::statement('LOCK TABLE repro_loss_cases IN SHARE ROW EXCLUSIVE MODE');
        $penId = $dam->pen_id; // where it happened (the maternity move uses a fresh copy)
        $case = ReproLossCase::create(['case_no' => (int) ReproLossCase::max('case_no') + 1, 'kidding_id' => $k->id, 'dam_id' => $dam->id, 'sire_id' => $sireId, 'pen_id' => $penId,
            'loss_type' => $type, 'occurred_on' => $k->kidded_on, 'gestation_days' => $k->gestation_days, 'kids_lost' => in_array($k->outcome, Kidding::LOSSES, true) ? 0 : (int) $k->stillborn, 'opened_by' => $by]);
        $others = $penId ? ReproLossCase::where('pen_id', $penId)->where('id', '!=', $case->id)->where('occurred_on', '>=', $k->kidded_on->copy()->subDays(self::CLUSTER_DAYS))->get() : collect();
        if ($others->isNotEmpty()) {
            $first = ! $others->contains('cluster', true);
            ReproLossCase::whereIn('id', $others->pluck('id')->push($case->id))->update(['cluster' => true]);
            if ($first) {
                Task::create(['title' => mb_substr('Reproductive loss cluster: '.($case->pen?->fullName() ?? 'pen'), 0, 190), 'type' => 'health', 'priority' => 'critical', 'status' => 'open',
                    'due_on' => now()->toDateString(), 'pen_id' => $penId, 'department_id' => Department::where('name', 'Veterinary')->value('id'),
                    'notes' => ($others->count() + 1).' losses in '.self::CLUSTER_DAYS.' days. Vet to investigate infectious causes; trained staff only handle the material, gloves on.']);
            }
        }

        return $case;
    }

    /** Why this doe may not be mated now, or null. */
    public static function breedingHold(string $damId): ?string
    {
        $cases = ReproLossCase::where('dam_id', $damId)->orderByDesc('id')->get();
        if ($c = $cases->first(fn ($c) => $c->breeding === 'do_not_breed')) {
            return "the vet advised not to breed her ({$c->label()})";
        }
        if ($c = $cases->first(fn ($c) => $c->breeding === 'delay' && $c->breed_after?->isFuture())) {
            return "the vet advised breeding again after {$c->breed_after->format('j M Y')} ({$c->label()})";
        }
        if ($c = $cases->first(fn ($c) => in_array($c->loss_type, ['abortion', 'early_loss'], true) && $c->breeding === null && $c->status !== 'closed')) {
            return "her pregnancy loss {$c->label()} is still under investigation";
        }

        return null;
    }

    public static function assertMayBreed(Animal $dam): void
    {
        if ($why = self::breedingHold($dam->id)) {
            throw ValidationException::withMessages(['dam_id' => "{$dam->farm_number} cannot be mated: {$why}."]);
        }
    }

    /** @return array<string, mixed> */
    public static function kpis(): array
    {
        $since = now()->subDays(365)->toDateString();
        $ended = Kidding::where('kidded_on', '>=', $since)->count();
        $losses = ReproLossCase::where('occurred_on', '>=', $since)->whereIn('loss_type', ['abortion', 'early_loss', 'stillbirth'])->count();
        $contained = ReproLossCase::whereNotNull('isolated_at')->where('occurred_on', '>=', $since)->get();

        return [
            'incidence' => $ended ? round($losses / $ended * 100, 1) : null, 'losses' => $losses, 'ended' => $ended,
            'containHours' => $contained->count() ? round($contained->avg(fn ($c) => $c->created_at->diffInMinutes($c->isolated_at) / 60), 1) : null,
            'open' => ReproLossCase::where('status', '!=', 'closed')->count(), 'clusters' => ReproLossCase::where('cluster', true)->where('occurred_on', '>=', $since)->distinct('pen_id')->count('pen_id'),
            'bySire' => ReproLossCase::with('sire')->where('occurred_on', '>=', $since)->whereNotNull('sire_id')->get()->groupBy('sire_id')
                ->map(fn ($g) => ['sire' => $g->first()->sire?->farm_number, 'losses' => $g->count()])->sortByDesc('losses')->values()->take(5)->all(),
        ];
    }
}
