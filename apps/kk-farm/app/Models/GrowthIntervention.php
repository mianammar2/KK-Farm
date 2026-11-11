<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-191: growth or health below target → nutrition or veterinary intervention → follow-up. */
class GrowthIntervention extends Model
{
    protected $guarded = [];

    protected $casts = ['opened_on' => 'date', 'follow_up_on' => 'date', 'closed_on' => 'date', 'weight_kg' => 'float', 'target_kg' => 'float', 'result_kg' => 'float'];

    public const KINDS = ['nutrition' => 'Nutrition Intervention', 'veterinary' => 'Veterinary Intervention'];

    public const STATUS = ['open' => 'Follow-up Due', 'improved' => 'Back on Track', 'not_improved' => 'Not Improved'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }
}
