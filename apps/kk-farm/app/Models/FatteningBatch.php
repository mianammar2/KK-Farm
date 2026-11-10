<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-187: a finishing batch (FTN-YYYY-NNNN). */
class FatteningBatch extends Model
{
    protected $guarded = [];

    protected $casts = ['started_on' => 'date', 'closed_on' => 'date', 'target_weight_kg' => 'float'];

    public const STATUS = ['planned' => 'Planned', 'active' => 'Active', 'target_reached' => 'Target Reached', 'sale_hold' => 'Sale Hold', 'sale_ready' => 'Sale Ready', 'closed' => 'Closed'];

    public function members()
    {
        return $this->hasMany(FatteningBatchAnimal::class, 'batch_id');
    }

    public function pen()
    {
        return $this->belongsTo(Pen::class);
    }

    public function ration()
    {
        return $this->belongsTo(RationPlan::class, 'ration_plan_id');
    }

    public static function nextCode(): string
    {
        $year = now()->year;
        $n = (int) self::where('code', 'like', "FTN-{$year}-%")->count() + 1;
        do {
            $code = sprintf('FTN-%d-%04d', $year, $n++);
        } while (self::where('code', $code)->exists());

        return $code;
    }
}
