<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-074: append-only medicine receipts, issues and adjustments (workbook "Medicine Movements"). */
class MedicineMovement extends Model
{
    protected $guarded = [];

    protected $casts = ['moved_on' => 'date', 'expiry_on' => 'date', 'qty' => 'float', 'rate_minor' => 'integer'];

    /** type => [label, direction]: +1 in, -1 out, 0 chosen by the user. */
    public const TYPES = [
        'opening' => ['Opening stock', 1], 'purchase' => ['Purchase', 1], 'return' => ['Returned to store', 1],
        'issue' => ['Issue to patient', -1], 'disposal' => ['Disposal (expired, damaged)', -1], 'adjustment' => ['Adjustment after count', 0], 'other' => ['Other', 0],
    ];

    public function medicine()
    {
        return $this->belongsTo(Medicine::class);
    }
}
