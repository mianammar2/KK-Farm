<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-211: humane end-of-life review. The vet assesses and performs; the owner or manager authorises. */
class EndOfLifeReview extends Model
{
    protected $guarded = [];

    protected $casts = ['vet_recommends' => 'boolean', 'vet_at' => 'datetime', 'approved_at' => 'datetime', 'performed_at' => 'datetime'];

    public const REASONS = ['untreatable_pain' => 'Pain that treatment cannot relieve', 'poor_prognosis' => 'No reasonable chance of recovery', 'severe_injury' => 'Severe injury',
        'chronic_disease' => 'Chronic or incurable disease', 'notifiable' => 'Disease control order', 'other' => 'Other'];

    public const STATUS = ['requested' => 'Waiting for vet assessment', 'assessed' => 'Vet supports: waiting for authorisation', 'approved' => 'Authorised: vet to perform',
        'declined' => 'Not supported / declined', 'done' => 'Done', 'withdrawn' => 'Withdrawn'];

    public function label(): string
    {
        return 'EOL-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function welfareCase()
    {
        return $this->belongsTo(WelfareCase::class);
    }
}
