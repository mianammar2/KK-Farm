<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-075: one pregnancy examination (workbook "Pregnancy Checks"). Append-only. */
class PregnancyCheck extends Model
{
    protected $guarded = [];

    protected $casts = ['checked_on' => 'date', 'next_check_on' => 'date'];

    public const METHODS = ['ultrasound' => 'Ultrasound', 'blood_test' => 'Blood test', 'vet_exam' => 'Vet exam', 'other' => 'Other'];

    public const RESULTS = ['pregnant' => 'Pregnant', 'not_pregnant' => 'Not pregnant', 'inconclusive' => 'Inconclusive', 'pregnancy_loss' => 'Pregnancy loss'];

    /** D-166: KK Farms ultrasound status (NSC "Not Scanned" is shown for a mating with no examination yet). */
    public const STATUSES = [
        'PCF' => 'Pregnancy Confirmed', 'VPG' => 'Viable Pregnancy', 'MPG' => 'Multiple Pregnancy', 'SPG' => 'Suspected Pregnancy',
        'INC' => 'Inconclusive Scan', 'NPG' => 'Not Pregnant (Open)', 'FHN' => 'Fetal Heartbeat Not Confirmed', 'SFL' => 'Suspected Fetal Loss',
        'CPL' => 'Confirmed Pregnancy Loss', 'HYD' => 'Pseudopregnancy / Hydrometra', 'ABN' => 'Abnormal Ultrasound Finding',
    ];

    public const NOT_SCANNED = ['NSC' => 'Not Scanned'];

    /** What each status means for the pregnancy record (the old result values). Suspected fetal loss never closes it. */
    public const RESULT_OF = [
        'PCF' => 'pregnant', 'VPG' => 'pregnant', 'MPG' => 'pregnant', 'SFL' => 'pregnant',
        'SPG' => 'inconclusive', 'INC' => 'inconclusive', 'FHN' => 'inconclusive', 'ABN' => 'inconclusive',
        'NPG' => 'not_pregnant', 'HYD' => 'not_pregnant', 'CPL' => 'pregnancy_loss',
    ];

    public const STATUS_OF = ['pregnant' => 'PCF', 'not_pregnant' => 'NPG', 'inconclusive' => 'INC', 'pregnancy_loss' => 'CPL'];

    public const VIABILITY = ['HBD' => 'Heartbeats Detected', 'HBN' => 'Heartbeat Not Confirmed', 'NAS' => 'Not Assessed'];

    /** Statuses that need a repeat ultrasound. */
    public const REPEAT = ['SPG', 'INC', 'FHN'];

    public static function fetalLabel(?int $n): string
    {
        return match (true) {
            $n === null => 'Fetal Count Not Confirmed', $n === 0 => 'No fetus detected', $n === 1 => 'Single Pregnancy',
            $n === 2 => 'Twin Pregnancy', $n === 3 => 'Triplet Pregnancy', default => 'Multiple Pregnancy',
        };
    }

    /** Recommended ultrasound activity for the day after mating. */
    public static function window(int $day): string
    {
        return match (true) {
            $day < 20 => 'Awaiting Pregnancy Examination', $day < 30 => 'Early examination only when indicated',
            $day < 45 => 'Initial Pregnancy Ultrasound', $day <= 85 => 'Pregnancy Confirmation & Fetal Count',
            default => 'Follow-up examination if clinically needed',
        };
    }

    /** Recommended next action for a result. */
    public static function action(string $status, ?int $fetuses): string
    {
        if (in_array($status, ['PCF', 'VPG', 'MPG'], true) && ($status === 'MPG' || ($fetuses ?? 0) >= 2)) {
            return 'Multiple pregnancy: extra nutrition and closer monitoring';
        }

        return match ($status) {
            'PCF', 'VPG' => 'Routine Pregnancy Monitoring', 'NPG' => 'Breeding Reassessment', 'SPG', 'INC' => 'Repeat Ultrasound',
            'FHN' => 'Veterinary Reassessment', 'SFL' => 'Veterinary Alert: suspected fetal loss', 'CPL' => 'Pregnancy Loss Follow-up',
            'HYD' => 'Veterinary Investigation and Treatment Plan', 'ABN' => 'Veterinary Investigation', default => '',
        };
    }

    /** D-166: the ultrasound form's choices. */
    public static function formOptions(): array
    {
        return ['ultrasound' => self::STATUSES, 'viability' => self::VIABILITY, 'checkMethods' => self::METHODS, 'repeat' => self::REPEAT];
    }

    public function mating()
    {
        return $this->belongsTo(Mating::class);
    }
}
