<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\Kidding;
use App\Models\Lactation;
use App\Models\Mating;
use App\Models\MilkRecord;
use App\Models\SaleClearance;
use App\Models\SaleOrderLine;
use App\Models\StatusHistory;
use App\Models\Weaning;
use App\Models\WeightRecord;

/**
 * D-232: the goat's independent statuses (KK Farms classification, phase 4). Each is worked out from records, so they
 * never conflict: a doe can be Pregnant and Milking at once. Every change is written to status_history with its rule.
 * Market weight is a flag, never a sale by itself: Sale Ready needs a recorded clearance and no blockers.
 */
final class Lifecycle
{
    public const LABELS = [
        'breeding' => ['not_ready' => 'Not ready for breeding', 'ready' => 'Ready for breeding', 'mated' => 'Mated', 'pregnant' => 'Pregnant', 'post_kidding' => 'Post-kidding',
            'open' => 'Open (not pregnant)', 'breeding_active' => 'Breeding buck', 'candidate' => 'Breeding candidate', 'blocked' => 'Breeding blocked (castrated)', 'not_breeding' => 'Not for breeding'],
        'lactation' => ['dry' => 'Dry', 'lactating' => 'Lactating', 'nursing' => 'Nursing', 'milking' => 'Milking', 'nursing_milking' => 'Nursing & milking'],
        'fattening' => ['none' => 'Not in fattening', 'in_programme' => 'In fattening programme', 'market_weight' => 'Market weight achieved'],
        'health' => ['healthy' => 'Healthy', 'watch' => 'Under observation', 'treatment' => 'Under treatment', 'critical' => 'Critical', 'vet_hold' => 'Veterinary hold (withdrawal)'],
        'sale' => ['not_for_sale' => 'Not for sale', 'growing' => 'Growing to market weight', 'market_weight' => 'Market weight achieved: clearance pending', 'sale_ready' => 'Sale ready',
            'blocked' => 'Sale blocked', 'reserved' => 'Reserved on an order', 'sold' => 'Sold'],
    ];

    /** status => rule id written to the history */
    private const RULES = [
        'pregnant' => 'MATING-CONFIRMED', 'post_kidding' => 'KIDDING-RECORDED', 'mated' => 'MATING-REGISTERED', 'ready' => 'BREEDING-APPROVED', 'blocked' => 'CASTRATION-RECORDED',
        'milking' => 'MILK-COLLECTED', 'nursing' => 'KIDS-NURSING', 'nursing_milking' => 'NURSING-AND-MILKING', 'dry' => 'DRY-OFF', 'lactating' => 'LACTATION-OPEN',
        'market_weight' => 'MARKET-WEIGHT', 'sale_ready' => 'SALE-CLEARANCE', 'vet_hold' => 'WITHDRAWAL-ACTIVE',
    ];

    /** @return array{male: float, female: float} the farm's market weight per sex (a finishing batch's own target wins) */
    public static function marketWeights(): array
    {
        $org = Tenant::org();

        return ['male' => (float) ($org->market_weight_male_kg ?? 25), 'female' => (float) ($org->market_weight_female_kg ?? 25)];
    }

    /** @return array<string, array{code: string, label: string, note?: string|null, blockers?: list<string>}> */
    public static function statuses(Animal $a): array
    {
        $female = $a->sex === 'female';
        $role = Designations::main($a);
        $lookups = self::lookups();
        $out = [];

        // Breeding
        if ($female) {
            $code = match (true) {
                isset(HerdStats::pregnantDams()['pregnant'][$a->id]) || (! isset(HerdStats::pregnantDams()['recorded'][$a->id]) && in_array($a->repro_status, ['pregnant', 'expected_kidding'], true)) => 'pregnant',
                isset($lookups['kidded'][$a->id]) => 'post_kidding',
                isset($lookups['mated'][$a->id]) => 'mated',
                $a->repro_status === 'ready' => 'ready',
                in_array($role, ['BD'], true) => 'open',
                default => 'not_ready',
            };
        } else {
            $code = match (true) {
                Designations::castrated($a) => 'blocked',
                $role === 'BB' => 'breeding_active',
                in_array($role, ['RB', 'GB'], true) => 'candidate',
                default => 'not_breeding',
            };
        }
        $out['breeding'] = ['code' => $code];

        // Lactation (does only): nursing and milking are separate facts and can both be true
        if ($female && ! in_array($role, ['DK'], true)) {
            $milking = isset($lookups['milked'][$a->id]);
            $nursing = isset($lookups['nursing'][$a->id]);
            $code = match (true) {
                $milking && $nursing => 'nursing_milking', $milking => 'milking', $nursing => 'nursing', isset($lookups['lactating'][$a->id]) => 'lactating', default => 'dry',
            };
            $hold = $code !== 'dry' && Milking::withdrawalOn($a->id, now()) !== null;
            $out['lactation'] = ['code' => $code, 'note' => $hold ? 'Milk hold: withdrawal, milk not for sale' : null];
        }

        // Fattening and market weight
        $member = Fattening::openMember($a->id);
        $target = $member?->batch?->target_weight_kg ?: self::marketWeights()[$female ? 'female' : 'male'];
        $weight = $lookups['weight'][$a->id] ?? null;
        $fattening = $member !== null || in_array($a->animal_purpose, ['fattening', 'meat'], true) || in_array($role, ['FM', 'FF'], true);
        $atWeight = $weight !== null && $weight >= $target;
        $out['fattening'] = ['code' => ! $fattening ? 'none' : ($atWeight ? 'market_weight' : 'in_programme'), 'note' => $fattening ? ($weight !== null ? "{$weight} KG of {$target} KG target" : "Target {$target} KG, not weighed") : null];

        // Health
        $withdrawal = Withdrawal::forAnimal($a->id);
        $out['health'] = ['code' => $withdrawal['meat'] || $withdrawal['milk'] ? 'vet_hold' : (in_array($a->health_status, ['watch', 'treatment', 'critical'], true) ? $a->health_status : 'healthy')];

        // Sale
        $saleStock = $fattening || in_array($a->animal_purpose, ['sale'], true) || in_array($role, ['CM', 'CF'], true) || $a->kid_stage === 'sale_selected';
        $cleared = ($lookups['clearance'][$a->id] ?? null) === 'cleared';
        $blockers = $a->status === 'active' && ($saleStock || $cleared) ? array_values(array_filter(SaleCheck::blockers($a), fn ($b) => ! str_starts_with($b, 'Already on order'))) : [];
        $code = match (true) {
            $a->status !== 'active' => $a->exit_reason === 'sold' || $a->exit_reason === 'sale' ? 'sold' : 'not_for_sale',
            isset($lookups['reserved'][$a->id]) => 'reserved',
            $cleared => $blockers ? 'blocked' : 'sale_ready',
            ! $saleStock => 'not_for_sale',
            ! $atWeight => 'growing',
            (bool) $blockers => 'blocked',
            default => 'market_weight',
        };
        $out['sale'] = ['code' => $code, 'blockers' => $blockers];

        foreach ($out as $dim => &$s) {
            $s['label'] = self::LABELS[$dim][$s['code']] ?? $s['code'];
        }

        return $out;
    }

    /** Herd-wide lookups, once per request. */
    private static function lookups(): array
    {
        return Tenant::memo('lifecycle_lookups', function () {
            $weanDays = (int) (Tenant::org()->weaning_age_days ?? 84);
            $weaned = array_flip(Weaning::where('status', 'weaned')->pluck('kid_id')->all());
            $nursing = Animal::active()->whereNotNull('dam_id')->where('birth_date', '>=', now()->subDays($weanDays)->toDateString())->get(['id', 'dam_id'])
                ->reject(fn ($k) => isset($weaned[$k->id]))->pluck('dam_id')->unique()->all();

            return [
                'kidded' => array_flip(Kidding::where('kidded_on', '>=', now()->subDays(30)->toDateString())->pluck('dam_id')->all()),
                'mated' => array_flip(Mating::where('status', 'open')->where('mated_on', '>=', now()->subDays(60)->toDateString())->pluck('dam_id')->all()),
                'lactating' => array_flip(Lactation::whereNull('dried_on')->pluck('animal_id')->all()),
                'milked' => array_flip(MilkRecord::where('milked_on', '>=', now()->subDays(3)->toDateString())->where('discarded', false)->whereIn('lactation_id', Lactation::whereNull('dried_on')->select('id'))->pluck('animal_id')->all()),
                'nursing' => array_flip($nursing),
                'weight' => WeightRecord::whereIn('id', WeightRecord::selectRaw('max(id)')->groupBy('animal_id'))->pluck('weight_kg', 'animal_id')->map(fn ($w) => (float) $w)->all(),
                'reserved' => array_flip(SaleOrderLine::whereIn('status', SaleOrderLine::OPEN)->pluck('animal_id')->all()),
                'clearance' => SaleClearance::whereIn('id', SaleClearance::selectRaw('max(id)')->groupBy('animal_id'))->pluck('decision', 'animal_id')->all(),
            ];
        });
    }

    /** Write any status (and age, teeth, role) that changed since the last record, with its rule. */
    public static function record(Animal $a): int
    {
        $now = self::statuses($a);
        $current = collect($now)->map(fn ($s) => $s['code'])->all() + array_filter(['age' => $a->ageStageCode(), 'teeth' => Teeth::code($a->teethCode()), 'role' => Designations::main($a)]);
        $last = StatusHistory::where('animal_id', $a->id)->whereIn('id', StatusHistory::where('animal_id', $a->id)->selectRaw('max(id)')->groupBy('dimension'))->pluck('to_status', 'dimension');
        $n = 0;
        foreach ($current as $dim => $code) {
            if (($last[$dim] ?? null) !== $code) {
                StatusHistory::create(['animal_id' => $a->id, 'dimension' => $dim, 'from_status' => $last[$dim] ?? null, 'to_status' => $code, 'changed_at' => now(),
                    'rule' => match ($dim) { 'age' => 'AGE-STAGE', 'teeth' => 'DENTAL-EXAM', 'role' => 'ROLE-DESIGNATION', default => self::RULES[$code] ?? strtoupper("{$dim}-{$code}") }]);
                $n++;
            }
        }

        return $n;
    }

    public static function recordAll(): int
    {
        return Animal::active()->get()->sum(fn (Animal $a) => self::record($a));
    }

    /** Forget cached lookups after a change in this request (tests, approvals). */
    public static function forget(): void
    {
        Tenant::flush();
    }
}
