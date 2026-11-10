<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-180: a shift checklist (Operations → Configuration). */
class ChecklistTemplate extends Model
{
    protected $guarded = [];

    protected $casts = ['items' => 'array', 'active' => 'boolean'];

    public const SHIFTS = ['morning' => 'Morning', 'afternoon' => 'Afternoon', 'evening' => 'Evening'];
}
