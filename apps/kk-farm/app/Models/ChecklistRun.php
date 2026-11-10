<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-180: one day's run of a shift checklist. */
class ChecklistRun extends Model
{
    protected $guarded = [];

    protected $casts = ['done' => 'array', 'run_on' => 'date', 'completed_at' => 'datetime', 'verified_at' => 'datetime'];

    public function template()
    {
        return $this->belongsTo(ChecklistTemplate::class, 'template_id');
    }
}
