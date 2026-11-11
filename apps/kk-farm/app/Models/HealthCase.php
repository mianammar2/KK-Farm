<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-073: one illness or injury episode (workbook "Health Cases"). */
class HealthCase extends Model
{
    protected $guarded = [];

    protected $casts = ['onset_on' => 'date', 'resolved_on' => 'date', 'isolated' => 'boolean', 'emergency' => 'boolean', 'review_on' => 'date', 'lab_sampled_on' => 'date', 'labour_hours' => 'float'];

    public const CONTAGION = ['none' => 'Not contagious', 'suspected' => 'Contagion suspected', 'confirmed' => 'Contagious (confirmed)'];

    public const SEVERITIES = ['low' => 'Low', 'moderate' => 'Moderate', 'high' => 'High', 'emergency' => 'Emergency'];

    public const STATUSES = ['open' => 'Open', 'under_treatment' => 'Under treatment', 'monitoring' => 'Monitoring', 'recovered' => 'Recovered', 'closed' => 'Closed', 'deceased' => 'Deceased'];

    public const ACTIVE = ['open', 'under_treatment', 'monitoring'];

    /** D-100: common goat conditions offered in a list (anything else is typed). The vet decides the diagnosis. */
    public const CONDITIONS = [
        'Pneumonia', 'Diarrhoea', 'Bloat', 'Fever', 'Worms / internal parasites', 'Ticks / mites / lice', 'Foot rot / lameness', 'Mastitis', 'Eye infection',
        'Skin disease', 'PPR (Peste des petits ruminants)', 'Enterotoxaemia', 'Abortion', 'Retained placenta', 'Weak kid', 'Poisoning', 'Off feed', 'Wound / injury', 'Fracture',
    ];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function treatments()
    {
        return $this->hasMany(Treatment::class)->orderBy('given_on')->orderBy('id');
    }

    public function outbreak()
    {
        return $this->belongsTo(Outbreak::class);
    }

    public function restrictedPen()
    {
        return $this->belongsTo(Pen::class, 'restricted_pen_id');
    }

    /** D-193: true case cost in minor units: medicine given + lab + labour hours at the farm's rate. */
    public function costMinor(int $labourRateMinor): array
    {
        $med = (int) $this->treatments->sum('cost_minor');
        $lab = (int) ($this->lab_cost_minor ?? 0);
        $labour = (int) round(($this->labour_hours ?? 0) * $labourRateMinor);

        return ['medicine' => $med, 'lab' => $lab, 'labour' => $labour, 'total' => $med + $lab + $labour];
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
