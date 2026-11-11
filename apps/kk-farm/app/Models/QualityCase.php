<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-189: a quality / compliance case QC-0001 (V3 App 21, flow 21). */
class QualityCase extends Model
{
    /** D-205: closing a case closes the incidents it came from. */
    protected static function booted(): void
    {
        static::updated(function (QualityCase $c) {
            if ($c->wasChanged('status') && $c->status === 'closed') {
                \App\Support\IncidentCapa::caseClosed($c);
            }
        });
    }

    protected $guarded = [];

    protected $casts = ['opened_on' => 'date', 'closed_at' => 'datetime', 'immediate_risk' => 'boolean'];

    public const SOURCES = [
        'milk' => 'Milk Quality', 'medicine' => 'Medicine Quality', 'feed' => 'Feed Safety', 'audit' => 'Audit Finding', 'complaint' => 'Customer Complaint',
        'outbreak' => 'Disease Outbreak', 'incident' => 'Incident', 'drill' => 'Failed Emergency Drill', 'other' => 'Other',
    ];

    public const SUBJECTS = ['animal' => 'Animal', 'milk_lot' => 'Milk Lot', 'feed_item' => 'Feed', 'medicine' => 'Medicine / Batch', 'process' => 'Process'];

    public const SEVERITY = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    public const STATUS = [
        'open' => 'Recorded', 'contained' => 'Contained', 'investigating' => 'Root Cause', 'capa' => 'CAPA', 'verifying' => 'Verifying',
        'reopened' => 'Reopened / Escalated', 'verified' => 'Verified', 'closed' => 'Closed',
    ];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function milkLot()
    {
        return $this->belongsTo(MilkLot::class);
    }

    public function feedItem()
    {
        return $this->belongsTo(FeedItem::class);
    }

    public function medicine()
    {
        return $this->belongsTo(Medicine::class);
    }

    public function capas()
    {
        return $this->hasMany(QualityCapa::class, 'case_id')->orderBy('id');
    }

    public function holds()
    {
        return $this->hasMany(QualityHold::class, 'case_id')->orderBy('id');
    }

    public function label(): string
    {
        return sprintf('QC-%04d', $this->case_no);
    }

    /** What the case is about, in words. */
    public function subjectName(): string
    {
        return match ($this->subject) {
            'animal' => 'Goat '.($this->animal?->farm_number ?? '?'),
            'milk_lot' => 'Milk lot '.($this->milkLot?->code ?? '?'),
            'feed_item' => 'Feed '.($this->feedItem?->name ?? '?'),
            'medicine' => 'Medicine '.($this->medicine?->name ?? '?').($this->batch ? " batch {$this->batch}" : ''),
            default => $this->process ?? 'Process',
        };
    }
}
