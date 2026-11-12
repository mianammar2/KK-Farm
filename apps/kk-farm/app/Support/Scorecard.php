<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\FatteningBatch;
use App\Models\Kidding;
use App\Models\LedgerEntry;
use App\Models\Mating;
use App\Models\MilkDispatch;
use App\Models\MilkRecord;
use App\Models\WeightRecord;
use Illuminate\Support\Collection;

/**
 * D-233: the CEO scorecard from the KK Farms classification framework (section 11), built from the same facts as the
 * rest of the system: age stages, teeth, roles, eligibility, lifecycle statuses, milk, fattening and money. Main roles
 * add up to the herd; overlapping statuses (pregnant and milking) are counted on their own, never added to the total.
 * Every goat count opens those goats in the Goat Registry.
 */
final class Scorecard
{
    /** @return list<array{key: string, title: string, note?: string, items: list<array{label: string, value: int|float|string|null, url?: ?string, unit?: string}>}> */
    public static function build(Collection $goats, Collection $tags, bool $money): array
    {
        $count = fn (string $key) => $tags->filter(fn ($t) => in_array($key, $t, true))->count();
        $goat = fn (string $label, string $key) => ['label' => $label, 'value' => $count($key), 'url' => "/goats?show={$key}"];
        $year = now()->startOfYear()->toDateString();
        $d30 = now()->subDays(30)->toDateString();
        $d7 = now()->subDays(7)->toDateString();

        $elig = Eligibility::all();
        $batches = FatteningBatch::whereIn('status', ['active', 'target_reached', 'sale_hold', 'sale_ready'])->get();
        $batchKpis = $batches->map(fn ($b) => Fattening::batch($b));
        $milk7 = (float) MilkRecord::where('milked_on', '>=', $d7)->where('discarded', false)->sum('litres');
        $milkingDoes = $count('life_lactation_milking') + $count('life_lactation_nursing_milking');
        $milkRevenue = (float) MilkDispatch::where('dispatched_on', '>=', $d30)->get()->sum(fn ($m) => (float) $m->litres * ((int) $m->price_per_litre_minor) / 100);
        $checked = Mating::where('mated_on', '>=', now()->subYear()->toDateString())->whereIn('status', ['pregnant', 'not_pregnant', 'kidded', 'aborted'])->count();
        $conceived = Mating::where('mated_on', '>=', now()->subYear()->toDateString())->whereIn('status', ['pregnant', 'kidded', 'aborted'])->count();
        $bornYear = Animal::where('origin', 'born_on_farm')->where('birth_date', '>=', now()->subYear()->toDateString())->get(['status', 'exit_reason', 'birth_date', 'exit_date']);
        $weanDays = (int) (Tenant::org()->weaning_age_days ?? 84);
        $ofAge = $bornYear->filter(fn ($k) => $k->birth_date->diffInDays(now()) >= $weanDays);
        $survived = $ofAge->reject(fn ($k) => $k->exit_reason === 'died' && $k->exit_date && $k->birth_date->diffInDays($k->exit_date) < $weanDays);
        $adgs = self::herdAdg();

        $groups = [
            ['key' => 'population', 'title' => 'Herd Population', 'items' => [
                $goat('Total', 'total'), $goat('Female', 'female'), $goat('Male', 'male'),
                ['label' => 'Births this year', 'value' => (int) Kidding::where('kidded_on', '>=', $year)->sum('born_alive'), 'url' => '/breeding?tab=kidding'],
                ['label' => 'Deaths this year', 'value' => Animal::where('exit_reason', 'died')->where('exit_date', '>=', $year)->count(), 'url' => '/reports?section=exits'],
            ]],
            ['key' => 'age', 'title' => 'Age Distribution', 'items' => AgeStages::stages()->map(fn ($s) => $goat("{$s->code} · {$s->label}", "stage_{$s->code}"))->push($goat('Age not known', 'no_stage'))->all()],
            ['key' => 'teeth', 'title' => 'Dental Distribution', 'items' => Teeth::stages()->map(fn ($t) => $goat("{$t->key} · {$t->label}", "teeth_{$t->key}"))->push($goat('UNK · Not examined', 'teeth_UNK'))->all()],
            ['key' => 'stock', 'title' => 'Breeding Stock', 'items' => [$goat('BB · Breeding Bucks', 'role_BB'), $goat('BD · Breeding Does', 'role_BD'), $goat('RB · Replacement Bucks', 'role_RB'), $goat('RD · Replacement Does', 'role_RD'), $goat('PS · Proven Sires', 'role_PS'), $goat('PD · Proven Dams', 'role_PD')]],
            ['key' => 'eligibility', 'title' => 'Breeding Eligibility', 'items' => [
                ['label' => 'Qualified, approval pending', 'value' => $elig->where('status', 'qualified')->count(), 'url' => '/breeding/eligibility'],
                ['label' => 'Maturity review', 'value' => $elig->where('review', true)->count(), 'url' => '/breeding/eligibility'],
                ['label' => 'Not eligible yet', 'value' => $elig->where('status', 'not_eligible')->count(), 'url' => '/breeding/eligibility'],
                ['label' => 'Approved', 'value' => $elig->where('status', 'approved')->count(), 'url' => '/breeding/eligibility'],
            ]],
            ['key' => 'repro', 'title' => 'Reproduction', 'note' => 'A doe can be pregnant and milking at once: counted in both, not added to the herd.', 'items' => [
                $goat('Ready for breeding', 'life_breeding_ready'), $goat('Mated', 'life_breeding_mated'), $goat('Pregnant', 'life_breeding_pregnant'),
                ['label' => 'Near kidding', 'value' => Mating::where('status', 'pregnant')->whereDate('expected_kidding_on', '<=', now()->addDays((int) (Tenant::org()?->kidding_alert_days ?: 10))->toDateString())->count(), 'url' => '/breeding?tab=calendar'],
                $goat('Post-kidding', 'life_breeding_post_kidding'),
            ]],
            ['key' => 'dairy', 'title' => 'Dairy', 'items' => array_values(array_filter([
                ['label' => 'Lactating', 'value' => $count('life_lactation_lactating') + $count('life_lactation_milking') + $count('life_lactation_nursing') + $count('life_lactation_nursing_milking'), 'url' => '/milking'],
                ['label' => 'Milking', 'value' => $milkingDoes, 'url' => '/goats?show=life_lactation_milking'],
                $goat('Nursing kids', 'life_lactation_nursing'),
                ['label' => 'Milk, last 7 days', 'value' => round($milk7, 1), 'unit' => 'L', 'url' => '/milking'],
                $money ? ['label' => 'Milk revenue, 30 days', 'value' => round($milkRevenue), 'unit' => 'PKR', 'url' => '/dairy'] : null,
            ]))],
            ['key' => 'fattening', 'title' => 'Fattening', 'items' => [
                ['label' => 'Active batches', 'value' => $batches->count(), 'url' => '/fattening'],
                $goat('In fattening', 'life_fattening_in_programme'),
                $goat('Target weight achieved', 'life_fattening_market_weight'),
                ['label' => 'Batch daily gain', 'value' => $batchKpis->whereNotNull('adg')->avg('adg') !== null ? (int) round($batchKpis->whereNotNull('adg')->avg('adg')) : null, 'unit' => 'g/day', 'url' => '/fattening'],
            ]],
            ['key' => 'commercial', 'title' => 'Commercial', 'items' => [
                ['label' => 'Sale eligible (clearance pending)', 'value' => $count('life_sale_market_weight'), 'url' => '/sales/readiness'],
                ['label' => 'Sale ready', 'value' => $count('life_sale_sale_ready'), 'url' => '/sales/readiness'],
                ['label' => 'Sale blocked', 'value' => $count('life_sale_blocked'), 'url' => '/sales/readiness'],
                ['label' => 'Reserved', 'value' => $count('life_sale_reserved'), 'url' => '/sales/orders'],
                ['label' => 'Sold this year', 'value' => Animal::where('exit_reason', 'sold')->where('exit_date', '>=', $year)->count(), 'url' => '/reports?section=exits'],
            ]],
            ['key' => 'health', 'title' => 'Health', 'items' => [$goat('Sick', 'sick'), $goat('Under treatment', 'life_health_treatment'), $goat('Critical', 'life_health_critical'), $goat('Under observation / recovery', 'life_health_watch'), $goat('Veterinary hold', 'life_health_vet_hold')]],
            ['key' => 'productivity', 'title' => 'Productivity', 'items' => [
                ['label' => 'Kid survival to weaning', 'value' => $ofAge->count() ? round($survived->count() / $ofAge->count() * 100) : null, 'unit' => '%', 'url' => '/growth'],
                ['label' => 'Conception rate, 12 months', 'value' => $checked ? round($conceived / $checked * 100) : null, 'unit' => '%', 'url' => '/breeding?tab=performance'],
                ['label' => 'Milk per milking doe a day', 'value' => $milkingDoes ? round($milk7 / 7 / $milkingDoes, 2) : null, 'unit' => 'L', 'url' => '/milking'],
                ['label' => 'Herd daily gain, 30 days', 'value' => $adgs, 'unit' => 'g/day', 'url' => '/dashboard/weight-change'],
            ]],
        ];
        if ($money) {
            $out30 = (float) LedgerEntry::where('direction', 'out')->where('occurred_on', '>=', $d30)->sum('amount');
            $feed30 = (float) LedgerEntry::where('direction', 'out')->where('category', 'Feed')->where('occurred_on', '>=', $d30)->sum('amount');
            $groups[] = ['key' => 'money', 'title' => 'Profitability (30 days)', 'items' => [
                ['label' => 'Cost per goat', 'value' => $goats->count() ? round($out30 / $goats->count()) : null, 'unit' => 'PKR', 'url' => '/finance'],
                ['label' => 'Feed cost', 'value' => round($feed30), 'unit' => 'PKR', 'url' => '/feeding'],
                ['label' => 'Dairy margin (milk revenue − feed share)', 'value' => $milkRevenue ? round($milkRevenue - ($goats->count() ? $feed30 * $milkingDoes / $goats->count() : 0)) : null, 'unit' => 'PKR', 'url' => '/dairy'],
                ['label' => 'Fattening margin, open batches', 'value' => $batchKpis->whereNotNull('margin')->count() ? round($batchKpis->sum('margin')) : null, 'unit' => 'PKR', 'url' => '/fattening'],
            ]];
        }

        return $groups;
    }

    /** Average daily gain over the last 30 days across goats weighed at least twice in that time. */
    private static function herdAdg(): ?int
    {
        $rows = WeightRecord::where('measured_on', '>=', now()->subDays(45)->toDateString())->orderBy('measured_on')->get(['animal_id', 'measured_on', 'weight_kg'])->groupBy('animal_id');
        $gains = $rows->map(fn ($w) => $w->count() >= 2 && ($days = $w->first()->measured_on->diffInDays($w->last()->measured_on)) >= 7 ? ($w->last()->weight_kg - $w->first()->weight_kg) * 1000 / $days : null)->filter(fn ($g) => $g !== null);

        return $gains->count() ? (int) round($gains->avg()) : null;
    }
}
