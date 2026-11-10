<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-185: a daily welfare / mobility check of a goat or a pen. */
class WelfareCheck extends Model
{
    protected $guarded = [];

    protected $casts = ['checked_on' => 'date', 'next_check_on' => 'date', 'pain' => 'boolean'];

    public const BEHAVIOUR = ['normal' => 'Normal', 'dull' => 'Dull / Depressed', 'restless' => 'Restless', 'aggressive' => 'Aggressive', 'isolated' => 'Isolating Itself'];

    public const INJURY = ['none' => 'None', 'minor' => 'Minor', 'serious' => 'Serious'];

    public const LAMENESS = [0 => '0 · Sound', 1 => '1 · Mild', 2 => '2 · Moderate', 3 => '3 · Severe'];

    public function pen()
    {
        return $this->belongsTo(Pen::class);
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
