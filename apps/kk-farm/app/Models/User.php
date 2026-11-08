<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    /** Same defaults as the database, so a user created in code is not mistaken for a disabled account. */
    protected $attributes = ['active' => true, 'must_change_password' => false];

    protected $casts = ['active' => 'boolean', 'must_change_password' => 'boolean', 'last_login_at' => 'datetime', 'password' => 'hashed'];

    public function canModule(string $module): bool
    {
        return in_array($this->role, config("kkfarm.modules.$module", []), true);
    }

    public function passes(string $gate): bool
    {
        return in_array($this->role, config("kkfarm.gates.$gate", []), true);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function roleLabel(): string
    {
        return config("kkfarm.roles.$this->role", $this->role);
    }
}
