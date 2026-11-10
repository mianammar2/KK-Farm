<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-186: the state of one water point in a round. */
class WaterCheck extends Model
{
    protected $guarded = [];

    protected $casts = ['available' => 'boolean'];

    public const QUALITY = ['clean' => 'Clean', 'dirty' => 'Dirty', 'smell' => 'Bad Smell', 'algae' => 'Algae', 'empty' => 'Empty / Dry'];
}
