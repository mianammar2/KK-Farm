<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-181: a leave request and the owner's or manager's decision. */
class LeaveRequest extends Model
{
    protected $guarded = [];

    protected $casts = ['from_on' => 'date', 'to_on' => 'date', 'decided_at' => 'datetime'];

    public const KINDS = ['annual' => 'Annual', 'sick' => 'Sick', 'casual' => 'Casual', 'unpaid' => 'Unpaid'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function days(): int
    {
        return (int) $this->from_on->diffInDays($this->to_on) + 1;
    }
}
