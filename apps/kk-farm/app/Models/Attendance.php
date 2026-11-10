<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-181: one employee's attendance for one day. */
class Attendance extends Model
{
    protected $guarded = [];

    protected $casts = ['work_on' => 'date'];

    public const STATUS = ['present' => 'Present', 'half_day' => 'Half Day', 'absent' => 'Absent', 'leave' => 'On Leave'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
