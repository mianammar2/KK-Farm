<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kidding extends Model
{
    protected $guarded = [];

    protected $casts = ['kidded_on' => 'date'];

    public const ASSISTED = ['no' => 'No', 'yes' => 'Yes', 'emergency' => 'Veterinary emergency'];

    /** Workbook litter type from the total born. */
    public static function litterType(int $total): string
    {
        return [0 => 'None', 1 => 'Single', 2 => 'Twin', 3 => 'Triplet', 4 => 'Quadruplet'][$total] ?? '5+';
    }

    /** D-117: a doe that kids is in milk: her lactation starts by itself. */
    protected static function booted(): void
    {
        static::created(function (self $k) {
            if ($k->dam_id && ($dam = Animal::find($k->dam_id))) {
                \App\Support\Milking::start($dam, $k->kidded_on, 'kidding', $k->recorded_by ?? 'System', $k);
            }
        });
    }

    public function kids()
    {
        return $this->hasMany(Animal::class, 'birth_kidding_id');
    }

    public function dam()
    {
        return $this->belongsTo(Animal::class, 'dam_id');
    }
}
