<?php

namespace App\Support;

use App\Models\Animal;

/** D-085/D-120: data for the Sheds and Pens board where goats are moved (on the Sheds page). */
class ShedBoard
{
    /**
     * D-085: every shed with all its pens (empty ones too, so goats can be moved into them) and the goats in each pen,
     * plus goats that have no pen. Moves go through Movements::transfer, so each one is a movement row and a life event.
     */
    public static function build(\Illuminate\Support\Collection $goats): array
    {
        $weights = \App\Support\Feed::latestWeights();
        $out = \App\Support\Sensors::outNow(); // D-087: goats out in an open area now
        $card = fn (Animal $a) => [
            'id' => $a->id, 'number' => $a->farm_number, 'name' => $a->name, 'sex' => $a->sex, 'age' => $a->effectiveAge(), 'ageLabel' => $a->ageLabel(),
            'flag' => $a->health_flag, 'weight' => isset($weights[$a->id]) ? round($weights[$a->id], 1) : null,
            'out' => isset($out[$a->id]) ? ['area' => $out[$a->id]->area?->fullName(), 'since' => $out[$a->id]->at->diffForHumans(short: true)] : null,
        ];
        $byPen = $goats->groupBy(fn ($g) => $g->pen_id ?? 0);
        $byId = $goats->keyBy('id');
        $sheds = \App\Models\Shed::with(['pens' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get()
            ->each(fn (\App\Models\Shed $s) => $s->pens->each->setRelation('shed', $s))->map(fn (\App\Models\Shed $s) => [
            'id' => $s->id, 'name' => $s->name, 'typeName' => $s->typeName(),
            'pens' => $s->pens->where('kind', 'pen')->map(fn (\App\Models\Pen $p) => [
                'id' => $p->id, 'name' => $p->fullName(), 'code' => $p->name, 'purpose' => $p->purpose, 'capacity' => $p->capacity,
                'goats' => ($byPen[$p->id] ?? collect())->map($card)->values()->all(),
            ])->values()->all(),
            'areas' => $s->pens->where('kind', 'open_area')->map(fn (\App\Models\Pen $p) => [
                'id' => $p->id, 'name' => $p->fullName(), 'code' => $p->name, 'purpose' => $p->purpose, 'capacity' => $p->capacity,
                'goats' => $out->where('area_id', $p->id)->map(fn ($x) => $byId[$x->animal_id] ?? null)->filter()->map($card)->values()->all(),
            ])->values()->all(),
        ])->values()->all();

        return ['sheds' => $sheds, 'noPen' => ($byPen[0] ?? collect())->map($card)->values()->all()];
    }
}
