<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-173: a vaccine or deworming protocol set by the vet (Veterinary → Configuration). */
class CareProtocol extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'dose_per_goat' => 'float'];

    public const APPLIES = ['all' => 'All goats', 'kids' => 'Kids', 'growers' => 'Growers', 'adults' => 'Adults', 'does' => 'Does (females)', 'bucks' => 'Bucks (males)', 'pregnant' => 'Pregnant does'];

    /** D-228: the fixed groups plus each of the farm's age stages ("stage:ADT-T02" => "ADT-T02 · Adult · 14–18 months"). */
    public static function applies(): array
    {
        return self::APPLIES + \App\Support\AgeStages::stages()->mapWithKeys(fn ($st) => ["stage:{$st->code}" => "{$st->code} · {$st->label} · ".($st->to_months === null ? (0 + $st->from_months).'+ months' : (0 + $st->from_months).'–'.(0 + $st->to_months).' months')])->all();
    }

    public function medicine()
    {
        return $this->belongsTo(Medicine::class);
    }
}
