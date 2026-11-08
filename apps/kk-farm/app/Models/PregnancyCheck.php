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

    public function mating()
    {
        return $this->belongsTo(Mating::class);
    }
}
