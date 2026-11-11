<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-189: a corrective or preventive action with an owner and a due date, approved, executed and independently verified. */
class QualityCapa extends Model
{
    protected $guarded = [];

    protected $casts = ['due_on' => 'date', 'approved_at' => 'datetime', 'executed_at' => 'datetime', 'verified_at' => 'datetime'];

    public const KINDS = ['corrective' => 'Corrective', 'preventive' => 'Preventive'];

    public const STATUS = ['planned' => 'Planned', 'approved' => 'Approved', 'executed' => 'Executed', 'passed' => 'Verified', 'failed' => 'Failed Verification'];

    public function qualityCase()
    {
        return $this->belongsTo(QualityCase::class, 'case_id');
    }
}
