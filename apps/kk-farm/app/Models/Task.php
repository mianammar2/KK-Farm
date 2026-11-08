<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $guarded = [];

    protected $casts = ['due_on' => 'date', 'completed_at' => 'datetime'];

    /** D-078: workbook "Tasks" vocabulary. */
    public const TYPES = ['feeding' => 'Feeding', 'health' => 'Health', 'breeding' => 'Breeding', 'pregnancy_check' => 'Pregnancy check', 'kidding' => 'Kidding', 'weaning' => 'Weaning',
        'weighing' => 'Weighing', 'cleaning' => 'Cleaning', 'inventory' => 'Inventory', 'maintenance' => 'Maintenance', 'biosecurity' => 'Biosecurity', 'observation' => 'Observation', 'other' => 'Other'];

    public const PRIORITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    public const STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'blocked' => 'Blocked', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

    public const OPEN = ['open', 'in_progress', 'blocked'];

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pen()
    {
        return $this->belongsTo(Pen::class);
    }
}
