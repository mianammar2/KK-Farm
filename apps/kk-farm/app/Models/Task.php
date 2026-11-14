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

    /**
     * D-241 (RBAC §9): the owner and manager see every task; everyone else only tasks given to them or to their
     * department (their own department, or their role's usual one when none is set). Tasks with no owner are management's.
     */
    public function scopeVisibleTo($q, User $u)
    {
        if ($u->passes('see_all_tasks')) {
            return $q;
        }
        $dept = $u->department_id ?? Department::forRole($u->role);

        return $q->where(fn ($w) => $w->where('user_id', $u->id)->when($dept, fn ($x) => $x->orWhere(fn ($y) => $y->where('department_id', $dept)->where(fn ($z) => $z->whereNull('user_id')->orWhere('user_id', $u->id)))));
    }

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
