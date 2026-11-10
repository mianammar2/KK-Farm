<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Animal extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['birth_date' => 'date', 'exit_date' => 'date', 'monitor_until' => 'date', 'acquired_on' => 'date', 'birth_date_is_estimate' => 'boolean', 'custom_fields' => 'array', 'birth_weight_kg' => 'float'];

    /**
     * Age category, worked out from the birth date today so it moves on by itself as the goat ages (D-067).
     * A category chosen by hand is used only when the birth date is unknown.
     */
    public function effectiveAge(): ?string
    {
        return $this->birth_date ? \App\Support\Classification::ageCategory($this->birth_date) : $this->age_category;
    }

    /** Goat category: chosen by hand, or suggested from gender, age today and purpose (D-047). */
    public function effectiveCategory(): ?string
    {
        return $this->animal_class ?: \App\Support\Classification::suggestCategory($this->sex, $this->effectiveAge(), $this->animal_purpose);
    }

    /** "Breeding Female (Doe) · Makhi Cheeni (Beetal Variety) · Breeding · Pregnant" (D-046) */
    public function classification(): string
    {
        return \App\Support\Classification::summary($this->effectiveCategory(), $this->breed?->name, $this->animal_purpose, $this->repro_status, $this->kid_stage, $this->sex);
    }

    public function breed()
    {
        return $this->belongsTo(Breed::class);
    }

    public function pen()
    {
        return $this->belongsTo(Pen::class);
    }

    public function feedingGroup()
    {
        return $this->belongsTo(FeedingGroup::class);
    }

    public function sire()
    {
        return $this->belongsTo(Animal::class, 'sire_id');
    }

    public function dam()
    {
        return $this->belongsTo(Animal::class, 'dam_id');
    }

    public function assignments()
    {
        return $this->hasMany(RfidAssignment::class)->latest('valid_from');
    }

    public function openAssignment()
    {
        return $this->hasOne(RfidAssignment::class)->whereNull('valid_to');
    }

    public function weights()
    {
        return $this->hasMany(WeightRecord::class)->orderBy('measured_on');
    }

    public function latestWeight()
    {
        return $this->hasOne(WeightRecord::class)->latestOfMany('measured_on');
    }

    public function healthRecords()
    {
        return $this->hasMany(HealthRecord::class)->latest('occurred_on');
    }

    public function events()
    {
        return $this->hasMany(AnimalEvent::class)->latest('occurred_at');
    }

    public function photos()
    {
        return $this->hasMany(AnimalPhoto::class)->latest();
    }

    /** D-071: the register's health flag and the health screens' status are kept in step, whichever one is changed. */
    public const FLAG_TO_STATUS = ['healthy' => 'healthy', 'monitor' => 'watch', 'recovering' => 'watch', 'sick' => 'treatment', 'quarantine' => 'treatment'];

    public const STATUS_TO_FLAG = ['healthy' => 'healthy', 'watch' => 'monitor', 'treatment' => 'sick', 'critical' => 'sick'];

    protected static function booted(): void
    {
        static::saving(function (self $a) {
            if ($a->isDirty('health_flag') && $a->health_flag) {
                $a->health_status = self::FLAG_TO_STATUS[$a->health_flag] ?? 'healthy';
            } elseif ($a->isDirty('health_status') || ! $a->health_flag) {
                $a->health_flag = self::STATUS_TO_FLAG[$a->health_status ?? 'healthy'] ?? 'healthy';
                $a->health_status ??= 'healthy';
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function ageLabel(): ?string
    {
        if (! $this->birth_date) {
            return null;
        }
        $months = (int) $this->birth_date->diffInMonths($this->exit_date ?? now());

        return $months.' mo'.($this->birth_date_is_estimate ? ' (est.)' : ''); // D-092: the farm counts age in months only
    }

    /** Compact shape used by lists, tiles and search. */
    public function card(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->farm_number,
            'name' => $this->name,
            'sex' => $this->sex,
            'coat' => $this->coat,
            'breed' => $this->breed?->name,
            'age' => $this->ageLabel(),
            'stage' => $this->effectiveAge(), // D-148: kid | grower | adult, for the age symbol
            'teeth' => $this->teeth, // D-106
            'ageMonths' => $this->birth_date ? (int) $this->birth_date->diffInMonths($this->exit_date ?? now()) : null,
            'pen' => $this->pen?->fullName(),
            'penId' => $this->pen_id,
            'status' => $this->status,
            'health' => $this->health_status,
            'repro' => $this->reproNow(), // D-162: 'monitoring' for 3 days after a delivery or loss
            'monitorUntil' => $this->isMonitored() ? $this->monitor_until->format('j M') : null,
            'weight' => $this->latestWeight?->weight_kg,
            'photo' => $this->photos->firstWhere('is_profile', true)?->id,
        ];
    }

    /** D-162: under monitoring after a delivery or a pregnancy loss (until monitor_until, inclusive). */
    public function isMonitored(): bool
    {
        return $this->monitor_until !== null && $this->monitor_until->greaterThanOrEqualTo(now()->startOfDay());
    }

    /** D-162: the reproductive state to show: 'monitoring' while monitored, then the stored one. */
    public function reproNow(): ?string
    {
        return $this->isMonitored() ? 'monitoring' : $this->repro_state;
    }
}
