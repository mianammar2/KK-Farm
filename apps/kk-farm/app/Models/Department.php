<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** D-052: a team inside one organization. Tasks can go to a whole department or to one of its users. */
class Department extends Model
{
    protected $guarded = [];

    public const DEFAULTS = ['Management', 'Veterinary', 'Caretaking', 'Feeding', 'Cleaning', 'Finance'];

    /** The department a new user lands in when none is chosen. */
    public const ROLE_DEFAULT = [
        'ceo' => 'Management', 'manager' => 'Management', 'vet' => 'Veterinary', 'caretaker' => 'Caretaking',
        'feeding' => 'Feeding', 'cleaning' => 'Cleaning', 'finance' => 'Finance',
    ];

    public static function seedDefaults(string $orgId): void
    {
        foreach (self::DEFAULTS as $name) {
            DB::table('departments')->insertOrIgnore(['org_id' => $orgId, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public static function forRole(string $role): ?int
    {
        $name = self::ROLE_DEFAULT[$role] ?? null;

        return $name ? self::where('name', $name)->value('id') : null;
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
