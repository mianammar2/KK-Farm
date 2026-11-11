<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-207: a pen cleaning; a deep clean or disinfection empties the pen and is verified by someone else. */
class PenCleaning extends Model
{
    protected $guarded = [];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime', 'verified_at' => 'datetime'];

    public const KINDS = ['routine' => 'Routine clean (manure, bedding)', 'deep' => 'Deep clean', 'disinfection' => 'Disinfection', 'after_outbreak' => 'After disease outbreak', 'after_loss' => 'After abortion / death'];

    /** Kinds that need the pen empty and closed while they run. */
    public const EMPTY_PEN = ['deep', 'disinfection', 'after_outbreak', 'after_loss'];

    public function pen()
    {
        return $this->belongsTo(Pen::class);
    }
}
