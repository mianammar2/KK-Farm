<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-189: a standard operating procedure; its text lives in append-only revisions. */
class Sop extends Model
{
    protected $guarded = [];

    protected $casts = ['review_on' => 'date', 'active' => 'boolean'];

    public const AREAS = [
        'milking' => 'Milking & Milk Handling', 'feed' => 'Feed Safety', 'medicine' => 'Medicine & Withdrawal', 'biosecurity' => 'Biosecurity & Quarantine',
        'welfare' => 'Welfare & Handling', 'water' => 'Water & Environment', 'dispatch' => 'Dispatch & Sale', 'safety' => 'Staff Safety', 'other' => 'Other',
    ];

    public function revisions()
    {
        return $this->hasMany(SopRevision::class)->orderByDesc('version');
    }
}
