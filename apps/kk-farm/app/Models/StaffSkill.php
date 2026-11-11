<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-210: a skill or authorisation with its evidence and expiry; revoked, never deleted. */
class StaffSkill extends Model
{
    protected $guarded = [];

    protected $casts = ['assessed_on' => 'date', 'valid_until' => 'date'];

    public const SKILLS = [
        'vet_licence' => 'Veterinary licence', 'drug_administration' => 'Authorised to give medicines', 'dairy_release' => 'Authorised to release milk',
        'financial_approval' => 'Authorised to approve payments', 'milker' => 'Milker', 'equipment' => 'Equipment / machinery operator', 'driver' => 'Livestock driver',
        'biohazard' => 'Biohazard handling', 'other' => 'Other',
    ];

    public const LEVELS = ['trainee' => 'Trainee', 'competent' => 'Competent', 'supervisor' => 'Supervisor / trainer'];

    /** Authorisations the handbook keeps separate (drug administration, dairy release, financial approval). */
    public const AUTHORISATIONS = ['vet_licence', 'drug_administration', 'dairy_release', 'financial_approval'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function valid(): bool
    {
        return $this->status === 'active' && (! $this->valid_until || $this->valid_until->gte(today()));
    }
}
