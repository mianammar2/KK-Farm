<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Support\EventContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** One change of pen. The animal's current pen and every pen's headcount are derived from these rows (D-038). */
class AnimalMove extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'datetime'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    /** Called by the Animal observer, so no code path can change a pen without leaving a move. */
    public static function recordMove(Animal $animal, ?int $from, ?int $to): self
    {
        $context = EventContext::consumeMove();
        $attributes = [
            'animal_id' => $animal->id, 'from_pen_id' => $from, 'to_pen_id' => $to, 'occurred_at' => now(),
            'reason' => $context['reason'] ?? ($to === null ? 'left_farm' : ($from === null ? 'placement' : 'transfer')),
            'recorded_by' => Auth::user()?->name ?? 'system',
        ];

        return ($context['event_uuid'] ?? null) ? self::appendOnce($context['event_uuid'], $attributes) : self::create($attributes);
    }
}
