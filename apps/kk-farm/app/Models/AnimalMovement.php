<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-076: one pen transfer or exit (workbook "Animal Movements"). Append-only. */
class AnimalMovement extends Model
{
    protected $guarded = [];

    protected $casts = ['moved_on' => 'date', 'amount_minor' => 'integer'];

    /** type => [label, leaves the farm, lifecycle status after]. */
    public const TYPES = [
        'transfer' => ['Pen transfer', false, null],
        'sale' => ['Sale', true, 'left_farm'],
        'death' => ['Death', true, 'deceased'],
        'culling' => ['Culling', true, 'left_farm'],
        'sacrificed' => ['Qurbani / sacrifice', true, 'left_farm'],
        'gifted' => ['Gift', true, 'left_farm'],
        'transferred_out' => ['Transferred out', true, 'left_farm'],
        'other' => ['Other exit', true, 'left_farm'],
    ];

    /** Exits where the goat may be eaten: blocked while it is under meat withdrawal (D-073). */
    public const MEAT_EXITS = ['sale', 'culling', 'sacrificed', 'gifted'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function fromPen()
    {
        return $this->belongsTo(Pen::class, 'from_pen_id');
    }

    public function toPen()
    {
        return $this->belongsTo(Pen::class, 'to_pen_id');
    }
}
