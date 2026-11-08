<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-073: one illness or injury episode (workbook "Health Cases"). */
class HealthCase extends Model
{
    protected $guarded = [];

    protected $casts = ['onset_on' => 'date', 'resolved_on' => 'date', 'isolated' => 'boolean'];

    public const SEVERITIES = ['low' => 'Low', 'moderate' => 'Moderate', 'high' => 'High', 'emergency' => 'Emergency'];

    public const STATUSES = ['open' => 'Open', 'under_treatment' => 'Under treatment', 'monitoring' => 'Monitoring', 'recovered' => 'Recovered', 'closed' => 'Closed', 'deceased' => 'Deceased'];

    public const ACTIVE = ['open', 'under_treatment', 'monitoring'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function treatments()
    {
        return $this->hasMany(Treatment::class)->orderBy('given_on')->orderBy('id');
    }

    public function label(): string
    {
        return sprintf('HC-%04d', $this->case_no);
    }

    /** Days since onset, up to the resolved date while open (workbook "Case Age Days"). */
    public function ageDays(): int
    {
        return (int) $this->onset_on->diffInDays($this->resolved_on ?? now()->startOfDay());
    }
}
