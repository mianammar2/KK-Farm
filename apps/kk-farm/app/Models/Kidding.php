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

    public function kids()
    {
        return $this->hasMany(Animal::class, 'birth_kidding_id');
    }

    public function dam()
    {
        return $this->belongsTo(Animal::class, 'dam_id');
    }
}
