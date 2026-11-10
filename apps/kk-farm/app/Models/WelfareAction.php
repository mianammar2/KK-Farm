<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-185: one step taken on a welfare case. */
class WelfareAction extends Model
{
    protected $guarded = [];

    protected $casts = ['done_on' => 'date', 'improved' => 'boolean'];

    public const KINDS = [
        'vet_referral' => 'Referred to the Vet (isolate / treat under vet plan)', 'hoof' => 'Hoof Trimming / Footbath', 'handling' => 'Handling Change', 'habitat' => 'Habitat / Pen Change',
        'follow_up' => 'Follow-up Check', 'escalate' => 'Escalated / Repeat Clinical Review', 'close' => 'Closed with Evidence',
    ];
}
