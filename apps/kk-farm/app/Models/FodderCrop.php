<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-202: one crop on a field, from sowing to its last cut. */
class FodderCrop extends Model
{
    protected $guarded = [];

    protected $casts = ['sown_on' => 'date', 'expected_harvest_on' => 'date', 'area_acres' => 'float'];

    public const CROPS = ['berseem' => 'Berseem (clover)', 'lucerne' => 'Lucerne (alfalfa)', 'maize' => 'Maize', 'sorghum' => 'Sorghum (chari)', 'millet' => 'Millet (bajra)',
        'oats' => 'Oats', 'mott_grass' => 'Mott grass', 'rhodes' => 'Rhodes grass', 'other' => 'Other'];

    public function field()
    {
        return $this->belongsTo(FodderField::class, 'field_id');
    }

    public function inputs()
    {
        return $this->hasMany(FodderInput::class, 'crop_id')->orderBy('applied_on');
    }

    public function lots()
    {
        return $this->hasMany(FodderLot::class, 'crop_id')->orderBy('harvested_on');
    }

    public function label(): string
    {
        return (self::CROPS[$this->crop] ?? $this->crop).($this->variety ? " {$this->variety}" : '').' · '.$this->field?->name;
    }
}
