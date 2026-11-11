<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-185: a welfare case (V3 App 19). */
class WelfareCase extends Model
{
    protected $guarded = [];

    protected $casts = ['opened_on' => 'date', 'closed_at' => 'datetime', 'handling_cleared_at' => 'datetime'];

    public const ISSUES = [
        'lameness' => 'Lameness / Mobility', 'injury' => 'Injury', 'body_condition' => 'Poor Body Condition', 'behaviour' => 'Abnormal Behaviour', 'aggression' => 'Aggression / Bullying',
        'space' => 'Crowding / Space', 'feed_water' => 'Feed or Water Access', 'heat' => 'Heat / Cold Stress', 'neglect' => 'Suspected Neglect', 'other' => 'Other',
    ];

    public const SEVERITY = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    public const STATUS = ['open' => 'Triage', 'vet_referral' => 'Under Vet Plan', 'action' => 'Care Action', 'follow_up' => 'Follow-up', 'escalated' => 'Escalated', 'closed' => 'Closed'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function pen()
    {
        return $this->belongsTo(Pen::class);
    }

    public function healthCase()
    {
        return $this->belongsTo(HealthCase::class);
    }

    public function recurrenceOf()
    {
        return $this->belongsTo(self::class, 'recurrence_of_id');
    }

    public function actions()
    {
        return $this->hasMany(WelfareAction::class, 'case_id')->orderBy('done_on')->orderBy('id');
    }

    public function label(): string
    {
        return sprintf('WC-%04d', $this->case_no);
    }
}
