<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\HealthCase;
use App\Models\Kidding;
use App\Models\Mating;
use App\Models\Organization;
use App\Models\Weaning;
use Illuminate\Support\Collection;

/**
 * D-201: journey L Genetic Selection-to-Replacement (V3 flow 35). Growth → Breeding → Livestock → Housing.
 * Merit comes from measured progeny (litter size, kid survival to weaning, kid growth, kid health), never from
 * pedigree completeness, which is shown on its own. Inbreeding is the coefficient of inbreeding (COI) of the kid a
 * mating would produce, from the farm's own pedigree (kinship by the recursive method; unknown parents count as
 * unrelated, so a thin pedigree understates it).
 */
final class Genetics
{
    public const MIN_KIDS = 3; // fewer measured kids: "not enough progeny"

    /** @var array<string, array{0: ?string, 1: ?string, 2: int}>|null id => [sire, dam, generation] */
    private static ?array $ped = null;

    private static array $kin = [];

    public static function flush(): void
    {
        self::$ped = null;
        self::$kin = [];
    }

    private static function ped(): array
    {
        if (self::$ped === null) {
            $rows = Animal::query()->toBase()->get(['id', 'sire_id', 'dam_id'])->keyBy('id');
            self::$ped = [];
            $gen = function (?string $id, int $d = 0) use (&$gen, $rows) {
                if (! $id || ! isset($rows[$id]) || $d > 30) {
                    return 0;
                }
                if (isset(self::$ped[$id])) {
                    return self::$ped[$id][2];
                }
                $r = $rows[$id];
                $g = 1 + max($gen($r->sire_id, $d + 1), $gen($r->dam_id, $d + 1));
                self::$ped[$id] = [$r->sire_id, $r->dam_id, $g];

                return $g;
            };
            foreach ($rows as $id => $r) {
                $gen($id);
            }
        }

        return self::$ped;
    }

    /** Kinship coefficient of two animals (probability two alleles drawn one from each are identical by descent). */
    public static function kinship(?string $a, ?string $b, int $depth = 0): float
    {
        $p = self::ped();
        if (! $a || ! $b || ! isset($p[$a], $p[$b]) || $depth > 14) {
            return 0.0;
        }
        if ($a === $b) {
            return (1 + self::inbreeding($a, $depth + 1)) / 2;
        }
        $key = strcmp($a, $b) < 0 ? "{$a}|{$b}" : "{$b}|{$a}";
        if (isset(self::$kin[$key])) {
            return self::$kin[$key];
        }
        [$x, $y] = $p[$a][2] >= $p[$b][2] ? [$a, $b] : [$b, $a]; // recurse on the younger generation
        $v = (self::kinship($p[$x][0], $y, $depth + 1) + self::kinship($p[$x][1], $y, $depth + 1)) / 2;

        return self::$kin[$key] = $v;
    }

    public static function inbreeding(string $id, int $depth = 0): float
    {
        $p = self::ped();

        return isset($p[$id]) ? self::kinship($p[$id][0], $p[$id][1], $depth) : 0.0;
    }

    /** COI % of the kid of this buck and doe. */
    public static function coi(?string $sireId, ?string $damId): float
    {
        return round(self::kinship($sireId, $damId) * 100, 2);
    }

    public static function limit(): float
    {
        return (float) (Organization::find(Tenant::current())?->inbreeding_limit_pct ?? 6.25);
    }

    /** Pedigree depth known: share of the 6 parents and grandparents on record (information, not merit). */
    public static function pedigreeKnown(Animal $a): int
    {
        $p = self::ped();
        $n = 0;
        foreach ([$a->sire_id, $a->dam_id] as $parent) {
            if ($parent && isset($p[$parent])) {
                $n += 1 + ($p[$parent][0] ? 1 : 0) + ($p[$parent][1] ? 1 : 0);
            } elseif ($parent) {
                $n++;
            }
        }

        return (int) round($n / 6 * 100);
    }

    /**
     * Progeny performance of every sire and dam with born-on-farm kids, and the herd averages.
     *
     * @return array{parents: Collection, herd: array<string, ?float>}
     */
    public static function progeny(): array
    {
        $org = Organization::findOrFail(Tenant::current());
        $kids = Animal::where('origin', 'born_on_farm')->where(fn ($q) => $q->whereNotNull('sire_id')->orWhereNotNull('dam_id'))
            ->get(['id', 'sire_id', 'dam_id', 'birth_date', 'birth_weight_kg', 'status', 'exit_reason', 'exit_date']);
        $wean = Weaning::where('status', 'completed')->whereNotNull('weight_kg')->get(['kid_id', 'weaned_on', 'weight_kg'])->keyBy('kid_id');
        $sick = HealthCase::whereIn('animal_id', $kids->modelKeys())->selectRaw('animal_id, count(*) as n')->groupBy('animal_id')->pluck('n', 'animal_id');
        $kiddings = Kidding::with('mating:id,sire_id')->get(['id', 'dam_id', 'mating_id', 'kidded_on', 'born_alive', 'stillborn']);

        $kidStats = $kids->map(function ($k) use ($org, $wean, $sick) {
            $died = $k->exit_reason === 'died' && $k->exit_date && $k->birth_date && $k->birth_date->diffInDays($k->exit_date) <= $org->weaning_age_days;
            $ofAge = $k->birth_date && $k->birth_date->diffInDays(now()) >= $org->weaning_age_days;
            $w = $wean[$k->id] ?? null;
            $adg = $w && $k->birth_date && $k->birth_weight_kg ? ((float) $w->weight_kg - (float) $k->birth_weight_kg) / max(1, $k->birth_date->diffInDays($w->weaned_on)) * 1000 : null;

            return ['sire' => $k->sire_id, 'dam' => $k->dam_id, 'counted' => $ofAge || $died, 'survived' => ($ofAge || $died) ? ! $died : null, 'adg' => $adg, 'cases' => (int) ($sick[$k->id] ?? 0)];
        });
        $litters = $kiddings->map(fn ($kd) => ['dam' => $kd->dam_id, 'sire' => $kd->mating?->sire_id, 'size' => (int) $kd->born_alive + (int) $kd->stillborn, 'still' => (int) $kd->stillborn, 'on' => $kd->kidded_on]);

        $summ = function (Collection $ks, Collection $ls) {
            $counted = $ks->where('counted', true);
            $adgs = $ks->pluck('adg')->filter(fn ($v) => $v !== null);
            $born = $ls->sum('size');

            return [
                'kids' => $ks->count(), 'litters' => $ls->count(),
                'litter' => $ls->count() ? round($ls->avg('size'), 2) : null,
                'stillPct' => $born ? round($ls->sum('still') / $born * 100, 1) : null,
                'survival' => $counted->count() ? round($counted->where('survived', true)->count() / $counted->count() * 100, 1) : null,
                'adg' => $adgs->count() ? (int) round($adgs->avg()) : null,
                'casesPerKid' => $ks->count() ? round($ks->sum('cases') / $ks->count(), 2) : null,
            ];
        };
        $herd = $summ($kidStats, $litters);
        $ids = $kidStats->pluck('sire')->merge($kidStats->pluck('dam'))->merge($litters->pluck('dam'))->merge($litters->pluck('sire'))->filter()->unique()->values();
        $animals = Animal::with('breed')->whereIn('id', $ids)->get()->keyBy('id');
        $parents = $ids->map(function ($id) use ($animals, $kidStats, $litters, $summ, $herd) {
            $a = $animals[$id] ?? null;
            if (! $a) {
                return null;
            }
            $role = $a->sex === 'male' ? 'sire' : 'dam';
            $s = $summ($kidStats->where($role, $id), $litters->where($role, $id));
            $intervals = $role === 'dam' ? $litters->where('dam', $id)->sortBy('on')->values()->sliding(2)->map(fn ($p) => $p[0]['on']->diffInDays($p[1]['on'])) : collect();

            return ['id' => $id, 'number' => $a->farm_number, 'role' => $role, 'breed' => $a->breed?->name, 'active' => $a->status === 'active', 'purpose' => $a->animal_purpose,
                'interval' => $intervals->count() ? (int) round($intervals->avg()) : null, 'merit' => self::merit($s, $herd), 'measured' => $s['kids'] >= self::MIN_KIDS] + $s;
        })->filter()->sortByDesc(fn ($p) => [$p['measured'] ? 1 : 0, $p['merit'] ?? 0])->values();

        return ['parents' => $parents, 'herd' => $herd];
    }

    /** Index 100 = herd average: mean of litter size, kid survival and kid ADG against the herd, less kid illness. */
    public static function merit(array $s, array $herd): ?int
    {
        if ($s['kids'] < self::MIN_KIDS) {
            return null;
        }
        $parts = collect([
            $s['litter'] && $herd['litter'] ? $s['litter'] / $herd['litter'] : null,
            $s['survival'] !== null && $herd['survival'] ? $s['survival'] / $herd['survival'] : null,
            $s['adg'] && $herd['adg'] ? $s['adg'] / $herd['adg'] : null,
        ])->filter(fn ($v) => $v !== null);
        if ($parts->isEmpty()) {
            return null;
        }
        $health = $herd['casesPerKid'] !== null && $s['casesPerKid'] !== null ? ($s['casesPerKid'] - $herd['casesPerKid']) * 10 : 0; // each extra case per kid costs 10 points

        return (int) round($parts->avg() * 100 - $health);
    }

    /** Matings above the inbreeding limit in the last 12 months, and the herd's pedigree completeness. */
    public static function kpis(): array
    {
        $since = now()->subYear()->toDateString();
        $matings = Mating::where('mated_on', '>=', $since)->whereNotNull('coi_pct')->get(['coi_pct']);
        $active = Animal::active()->get(['id', 'sire_id', 'dam_id']);

        return [
            'inbreedingAlerts' => Mating::where('mated_on', '>=', $since)->where('coi_pct', '>', self::limit())->count(),
            'avgCoi' => $matings->count() ? round($matings->avg('coi_pct'), 2) : null,
            'selected' => \App\Models\BreederSelection::where('decision', 'replacement')->where('decided_on', '>=', $since)->count(),
            'pedigreeComplete' => $active->count() ? (int) round($active->filter(fn ($a) => $a->sire_id && $a->dam_id)->count() / $active->count() * 100) : null,
        ];
    }
}
