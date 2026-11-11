<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-206: a reproductive loss investigation (RL-NNNN). */
class ReproLossCase extends Model
{
    protected $guarded = [];

    protected $casts = ['occurred_on' => 'date', 'isolated_at' => 'datetime', 'sampled_on' => 'date', 'diagnosed_at' => 'datetime', 'breed_after' => 'date', 'closed_at' => 'datetime',
        'ppe_used' => 'boolean', 'zoonotic_suspected' => 'boolean', 'cluster' => 'boolean'];

    public const TYPES = ['abortion' => 'Abortion', 'early_loss' => 'Early embryonic loss', 'stillbirth' => 'Stillbirth', 'premature' => 'Premature kidding', 'mixed' => 'Live and stillborn kids'];

    public const STATUS = ['open' => 'Needs containment', 'contained' => 'Contained: sample', 'sampled' => 'Waiting for diagnosis', 'diagnosed' => 'Diagnosed: plan breeding', 'closed' => 'Closed'];

    public const BREEDING = ['rebreed' => 'May be bred again', 'delay' => 'Breed again after a date', 'do_not_breed' => 'Do not breed (cull recommended)'];

    /** Kidding outcomes that open an investigation. */
    public const FROM_OUTCOMES = ['ABT' => 'abortion', 'EEL' => 'early_loss', 'STB' => 'stillbirth', 'PKD' => 'premature', 'MBO' => 'mixed'];

    public function dam()
    {
        return $this->belongsTo(Animal::class, 'dam_id');
    }

    public function sire()
    {
        return $this->belongsTo(Animal::class, 'sire_id');
    }

    public function pen()
    {
        return $this->belongsTo(Pen::class);
    }

    public function label(): string
    {
        return sprintf('RL-%04d', $this->case_no);
    }
}
