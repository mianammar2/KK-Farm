<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-182: a repair, preventive service or inspection of an asset. */
class MaintenanceJob extends Model
{
    protected $guarded = [];

    protected $casts = ['requested_on' => 'date', 'done_on' => 'date'];

    public const KINDS = ['repair' => 'Repair (Breakdown)', 'preventive' => 'Preventive Service', 'inspection' => 'Inspection'];

    public const PRIORITY = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
