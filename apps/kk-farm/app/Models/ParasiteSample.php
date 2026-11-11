<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-200: a faecal egg count / FAMACHA sample of a goat or a pooled pen, and the vet's decision. */
class ParasiteSample extends Model
{
    protected $guarded = [];

    protected $casts = ['sampled_on' => 'date', 'retest_due_on' => 'date', 'decided_at' => 'datetime', 'resistance_flag' => 'boolean', 'reduction_pct' => 'float'];

    public const REASONS = ['clinical' => 'Clinical signs', 'seasonal' => 'Seasonal schedule', 'suspected' => 'Suspected worm burden', 'retest' => 'Retest after dosing', 'monitoring' => 'Routine monitoring'];

    public const DECISIONS = ['treat' => 'Treat (targeted)', 'monitor' => 'Monitor, no dose now', 'no_treat' => 'No treatment needed'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function pen()
    {
        return $this->belongsTo(Pen::class);
    }

    public function retestOf()
    {
        return $this->belongsTo(self::class, 'retest_of_id');
    }

    public function label(): string
    {
        return sprintf('FEC-%04d', $this->sample_no);
    }
}
