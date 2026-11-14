<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-185: one step taken on a welfare case. */
class WelfareAction extends Model
{
    protected $guarded = [];

    protected $casts = ['done_on' => 'date', 'improved' => 'boolean', 'due_on' => 'date'];

    public const KINDS = [
        'vet_referral' => 'Referred to the Vet (isolate / treat under vet plan)', 'hoof' => 'Hoof Trimming / Footbath', 'handling' => 'Handling Change', 'habitat' => 'Habitat / PEN Change',
        'follow_up' => 'Follow-up Check', 'escalate' => 'Escalated / Repeat Clinical Review', 'close' => 'Closed with Evidence',
        'housing' => 'Moved to Care Housing', 'vet_update' => 'Vet Diagnosis / Update', 'handling_ok' => 'Vet Cleared Routine Handling', // D-199
    ];
}
