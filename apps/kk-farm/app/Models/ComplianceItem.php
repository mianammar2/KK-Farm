<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-189: an entry in the regulatory register (licence, permit, certificate…) with its expiry. */
class ComplianceItem extends Model
{
    protected $guarded = [];

    protected $casts = ['issued_on' => 'date', 'expires_on' => 'date', 'active' => 'boolean'];

    public const KINDS = [
        'licence' => 'Licence', 'permit' => 'Permit', 'certificate' => 'Certificate', 'registration' => 'Registration',
        'inspection' => 'Inspection', 'insurance' => 'Insurance', 'other' => 'Other',
    ];

    public const DUE_DAYS = 30;
}
