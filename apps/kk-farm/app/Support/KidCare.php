<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\KidCareRecord;

/**
 * D-176: newborn kids (14 days old or less) and what they still need: colostrum (within the first 6 hours of life),
 * weak kids (born weak or last nursing check weak / not suckling) and orphans (mother dead, gone, or fostered).
 */
final class KidCare
{
    public const DAYS = 14;

    public static function board(): array
    {
        $kids = Animal::active()->with(['breed', 'pen.shed', 'dam'])->where('birth_date', '>=', now()->subDays(self::DAYS)->toDateString())->orderByDesc('birth_date')->get();
        $records = KidCareRecord::whereIn('kid_id', $kids->modelKeys())->orderBy('given_at')->get()->groupBy('kid_id');

        return $kids->map(function (Animal $k) use ($records) {
            $r = $records[$k->id] ?? collect();
            $colostrum = $r->firstWhere('kind', 'colostrum');
            $lastCheck = $r->where('kind', 'nursing_check')->last();
            $damGone = $k->dam_id && $k->dam && $k->dam->status !== 'active';
            $orphan = $k->kid_stage === 'orphan' || $damGone;
            $weak = $k->birth_outcome === 'BAW' && ! ($lastCheck && $lastCheck->suckling === 'good') || ($lastCheck && $lastCheck->suckling !== 'good');
            $ageHours = (int) $k->birth_date->diffInHours(now());

            return [
                'id' => $k->id, 'number' => $k->farm_number, 'sex' => $k->sex, 'breed' => $k->breed?->name, 'pen' => $k->pen?->fullName(), 'born' => $k->birth_date->format('d/m'),
                'ageDays' => (int) $k->birth_date->diffInDays(now()), 'dam' => $k->dam?->farm_number, 'damId' => $k->dam_id,
                'colostrum' => $colostrum ? $colostrum->given_at->format('d/m g:i A') : null, 'colostrumDue' => ! $colostrum && $ageHours <= 72,
                'weak' => (bool) $weak, 'orphan' => $orphan, 'fosteredTo' => $r->where('kind', 'fostered')->last()?->foster_dam_id ? Animal::find($r->where('kind', 'fostered')->last()->foster_dam_id)?->farm_number : null,
                'lastCheck' => $lastCheck ? KidCareRecord::SUCKLING[$lastCheck->suckling] ?? null : null, 'feeds' => $r->where('kind', 'bottle_feed')->count(),
                'history' => $r->reverse()->take(5)->map(fn ($x) => ['kind' => KidCareRecord::KINDS[$x->kind], 'at' => $x->given_at->format('d/m g:i A'), 'ml' => $x->amount_ml, 'by' => $x->recorded_by])->values(),
            ];
        })->sortByDesc(fn ($k) => ($k['colostrumDue'] ? 4 : 0) + ($k['weak'] ? 2 : 0) + ($k['orphan'] ? 1 : 0))->values()->all();
    }
}
