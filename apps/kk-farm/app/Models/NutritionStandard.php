<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-116: the CP (and TDN) a nutrition group's whole daily diet should carry, on a dry-matter basis. */
class NutritionStandard extends Model
{
    protected $guarded = [];

    protected $casts = ['reviewed_on' => 'date', 'active' => 'boolean', 'cp_min_pct' => 'float', 'cp_max_pct' => 'float', 'tdn_min_pct' => 'float', 'tdn_max_pct' => 'float',
        'min_weight_kg' => 'float', 'max_weight_kg' => 'float'];

    public const METHODS = ['tmr' => 'TMR (Total Mixed Ration)', 'separate' => 'Roughage and Concentrate Separately', 'grazing' => 'Grazing plus Supplement'];

    public function breed()
    {
        return $this->belongsTo(Breed::class);
    }

    public function target(): float
    {
        return round(($this->cp_min_pct + $this->cp_max_pct) / 2, 1);
    }

    public function range(): string
    {
        $f = fn (float $v) => rtrim(rtrim(number_format($v, 1), '0'), '.');

        return $this->cp_min_pct === $this->cp_max_pct ? $f($this->cp_min_pct).'%' : $f($this->cp_min_pct).'–'.$f($this->cp_max_pct).'%';
    }
}
