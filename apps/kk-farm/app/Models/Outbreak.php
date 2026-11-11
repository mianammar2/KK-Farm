<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-193: a disease outbreak: contagious cases of one condition grouped for control and the outbreak report. */
class Outbreak extends Model
{
    protected $guarded = [];

    protected $casts = ['opened_on' => 'date', 'closed_on' => 'date'];

    public function cases()
    {
        return $this->hasMany(HealthCase::class);
    }
}
