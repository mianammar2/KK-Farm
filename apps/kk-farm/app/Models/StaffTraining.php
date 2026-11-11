<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-210: a training an employee received; append-only (a refresher is a new row). */
class StaffTraining extends Model
{
    protected $guarded = [];

    protected $casts = ['trained_on' => 'date', 'expires_on' => 'date'];

    public const TOPICS = [
        'handling' => 'Humane animal handling', 'biosecurity' => 'Biosecurity & hygiene', 'food_hygiene' => 'Food / milk & feed hygiene', 'first_aid' => 'First aid',
        'drug_admin' => 'Medicine administration & withdrawal', 'milking' => 'Milking routine', 'equipment' => 'Equipment & machinery', 'ppe' => 'PPE & chemical safety', 'other' => 'Other',
    ];

    /** Required training by job (matched on the employee's role label); everyone needs handling and biosecurity. */
    public const REQUIRED = [
        '*' => ['handling', 'biosecurity'],
        'caretaker' => ['first_aid'],
        'feeding' => ['food_hygiene'],
        'cleaning' => ['ppe'],
        'veterinarian' => ['drug_admin'],
        'manager' => ['first_aid'],
    ];

    /** @return array<int, string> */
    public static function requiredFor(Employee $e): array
    {
        $role = mb_strtolower((string) $e->role);
        $need = self::REQUIRED['*'];
        foreach (self::REQUIRED as $key => $topics) {
            if ($key !== '*' && str_contains($role, $key)) {
                $need = [...$need, ...$topics];
            }
        }

        return array_values(array_unique($need));
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
