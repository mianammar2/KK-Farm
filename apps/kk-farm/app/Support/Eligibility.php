<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\BreederSelection;
use App\Models\BreedingApproval;
use App\Models\BreedingRule;
use App\Models\GrowthIntervention;
use App\Models\HealthCase;
use App\Models\Task;
use App\Models\WeightRecord;
use App\Models\WelfareCase;
use Illuminate\Support\Collection;

/**
 * D-231: breeding eligibility (KK Farms classification, phase 3). The system checks the farm's rules and asks for
 * approval; it never approves by itself. Qualified ≠ approved: only an authorised person makes a goat a breeder.
 * Two teeth or a target weight alone only raise a Maturity Review, never an approval.
 */
final class Eligibility
{
    /** sex => [min age months, min weight kg, mature weight kg, min % of mature weight, min BCS, weight valid for days] */
    public const DEFAULTS = ['female' => [12, 35, null, 65, 3, 30], 'male' => [12, 35, null, 65, 3, 30]];

    public const STATUSES = [
        'qualified' => 'Qualified: approval pending', 'not_eligible' => 'Not eligible yet', 'approved' => 'Approved: ready for breeding',
        'rejected' => 'Not approved', 'breeder' => 'Already a breeder', 'not_candidate' => 'Not a breeding candidate',
    ];

    public const TASK_PREFIX = 'Breeding approval: ';

    /** The rule for the goat's breed and sex, else the farm-wide one (made from the defaults on first use). */
    public static function rule(?int $breedId, string $sex): BreedingRule
    {
        $rules = Tenant::memo('breeding_rules', function () {
            if (Tenant::current() && ! BreedingRule::whereNull('breed_id')->exists()) {
                foreach (self::DEFAULTS as $s => $d) {
                    BreedingRule::create(['breed_id' => null, 'sex' => $s, 'min_age_months' => $d[0], 'min_weight_kg' => $d[1], 'mature_weight_kg' => $d[2], 'min_mature_pct' => $d[3], 'min_bcs' => $d[4], 'weight_max_age_days' => $d[5]]);
                }
            }

            return Tenant::current() ? BreedingRule::all() : collect();
        });
        $d = self::DEFAULTS[$sex] ?? self::DEFAULTS['female'];

        return $rules->first(fn ($r) => $r->breed_id === $breedId && $r->sex === $sex)
            ?? $rules->first(fn ($r) => $r->breed_id === null && $r->sex === $sex)
            ?? new BreedingRule(['sex' => $sex, 'min_age_months' => $d[0], 'min_weight_kg' => $d[1], 'mature_weight_kg' => $d[2], 'min_mature_pct' => $d[3], 'min_bcs' => $d[4], 'weight_max_age_days' => $d[5]]);
    }

    /**
     * @return array{status: string, label: string, checks: array<string, array{ok: bool, label: string, info?: bool}>, review: bool, recommendation: ?string, approval: ?BreedingApproval}
     */
    public static function check(Animal $a): array
    {
        $approval = BreedingApproval::where('animal_id', $a->id)->latest('decided_on')->latest('id')->first();
        $role = Designations::main($a);
        $result = fn (string $status, array $checks = [], bool $review = false, ?string $rec = null) => ['status' => $status, 'label' => self::STATUSES[$status], 'checks' => $checks, 'review' => $review, 'recommendation' => $rec, 'approval' => $approval];

        if ($a->status !== 'active' || ! in_array($a->sex, ['male', 'female'], true) || Designations::castrated($a) || in_array($role, ['CM', 'CF', 'FM', 'FF', 'BK', 'DK'], true)) {
            return $result('not_candidate');
        }
        if ($approval?->decision === 'approved') {
            return $result('approved');
        }
        if (! $approval && in_array($role, ['BB', 'BD'], true)) {
            return $result('breeder'); // breeders from before D-231 are not sent back through approval
        }

        $rule = self::rule($a->breed_id, $a->sex);
        $months = $a->birth_date ? (int) $a->birth_date->diffInMonths(now()) : null;
        $w = WeightRecord::where('animal_id', $a->id)->latest('measured_on')->latest('id')->first();
        $fresh = $w && $w->measured_on->gte(now()->subDays($rule->weight_max_age_days));
        $bcs = WeightRecord::where('animal_id', $a->id)->whereNotNull('body_score')->latest('measured_on')->value('body_score');
        $sick = HealthCase::where('animal_id', $a->id)->whereIn('status', HealthCase::ACTIVE)->exists();
        $welfare = WelfareCase::where('animal_id', $a->id)->where('status', '!=', 'closed')->exists();
        $growth = GrowthIntervention::where('animal_id', $a->id)->where('status', 'open')->exists();
        $selected = BreederSelection::where('animal_id', $a->id)->where('decision', 'replacement')->latest('decided_on')->first();
        $pct = $w && $rule->mature_weight_kg ? (int) round($w->weight_kg / $rule->mature_weight_kg * 100) : null;
        $breed = $rule->breed_id ? ($a->breed?->name ?? 'this breed') : 'all breeds';

        $checks = [
            'age' => ['ok' => $months !== null && $months >= $rule->min_age_months, 'label' => ($months === null ? 'Age unknown' : "{$months} months").", needs {$rule->min_age_months}"],
            'weight' => ['ok' => $fresh && $w->weight_kg >= $rule->min_weight_kg, 'label' => ! $w ? 'No weight' : "{$w->weight_kg} KG on {$w->measured_on->format('j M')}".($fresh ? '' : ' (older than '.$rule->weight_max_age_days.' days: weigh again)').", needs {$rule->min_weight_kg} KG ({$breed})"],
            'mature' => $rule->mature_weight_kg
                ? ['ok' => $fresh && $pct !== null && $pct >= $rule->min_mature_pct, 'label' => ($pct === null ? 'No weight' : "{$pct}% of mature weight {$rule->mature_weight_kg} KG").", needs {$rule->min_mature_pct}%"]
                : ['ok' => true, 'info' => true, 'label' => 'Mature weight not set for this breed (not checked)'],
            'bcs' => $rule->min_bcs !== null
                ? ['ok' => $bcs !== null && $bcs >= $rule->min_bcs, 'label' => ($bcs === null ? 'Body condition not scored' : "Body condition {$bcs}").", needs {$rule->min_bcs}"]
                : ['ok' => true, 'info' => true, 'label' => 'Body condition not checked'],
            'health' => ['ok' => ! $sick && ! $welfare && ! $growth && ($a->health_status ?? 'healthy') === 'healthy', 'label' => $sick ? 'Open health case' : ($welfare ? 'Open welfare case' : ($growth ? 'Growth intervention open' : (($a->health_status ?? 'healthy') === 'healthy' ? 'Healthy, no open case' : 'Not healthy')))],
            'teeth' => ['ok' => true, 'info' => true, 'label' => in_array($a->teeth, ['T02', 'T04', 'T06', 'T08'], true) ? "{$a->teeth}: supports maturity" : 'Teeth '.Teeth::code($a->teeth).' (supporting evidence only)'],
            'genetics' => ['ok' => true, 'info' => true, 'label' => $selected ? "Selected as replacement breeder on {$selected->decided_on->format('j M Y')}" : 'Not yet selected as replacement: confirm the line at approval'],
        ];
        $qualified = collect($checks)->every(fn ($c) => $c['ok']);
        $review = ! $qualified && (in_array($a->teeth, ['T02', 'T04', 'T06', 'T08'], true) || ($w && $w->weight_kg >= $rule->min_weight_kg));
        if ($approval?->decision === 'rejected') {
            return $result('rejected', $checks, false, $a->sex === 'male' ? 'Fattening assessment recommended' : 'Re-assess later');
        }

        return $result($qualified ? 'qualified' : 'not_eligible', $checks, $review);
    }

    /** Candidates on the farm with their check, for the eligibility page and the dashboard. */
    public static function all(): Collection
    {
        return Tenant::memo('eligibility_all', fn () => Animal::active()->whereIn('sex', ['male', 'female'])->with('breed')->orderBy('farm_number')->get()
            ->map(fn (Animal $a) => ['goat' => $a] + self::check($a))->filter(fn ($r) => ! in_array($r['status'], ['not_candidate', 'breeder'], true))->values());
    }

    /** A qualified goat gets one open approval task; it closes when someone decides. */
    public static function sync(): void
    {
        foreach (self::all()->where('status', 'qualified') as $r) {
            self::task($r['goat']);
        }
    }

    /** After a weighing, a teeth check or a selection: the one goat, at once. */
    public static function syncOne(?Animal $g): void
    {
        if ($g && self::check($g)['status'] === 'qualified') {
            self::task($g);
        }
    }

    private static function task(Animal $g): void
    {
        if (! Task::where('animal_id', $g->id)->where('title', 'like', self::TASK_PREFIX.'%')->whereIn('status', Task::OPEN)->exists()) {
            Task::create(['title' => self::TASK_PREFIX.$g->farm_number, 'type' => 'breeding', 'priority' => 'medium', 'status' => 'open', 'due_on' => now()->addDays(3)->toDateString(),
                'animal_id' => $g->id, 'pen_id' => $g->pen_id, 'department_id' => \App\Models\Department::forRole('vet'), 'notes' => 'All breeding checks passed. Confirm the reproductive exam and genetics, then approve or reject in Breeding → Breeding Eligibility.']);
        }
    }
}
