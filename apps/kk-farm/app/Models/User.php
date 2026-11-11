<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    /** Same defaults as the database, so a user created in code is not mistaken for a disabled account. */
    protected $attributes = ['active' => true, 'must_change_password' => false];

    protected $casts = ['active' => 'boolean', 'biohazard_trained' => 'boolean', 'must_change_password' => 'boolean', 'last_login_at' => 'datetime', 'password' => 'hashed'];

    public function canModule(string $module): bool
    {
        if ($module === 'fodder' && $this->role !== 'ceo' && ! \App\Support\Fodder::enabled()) {
            return false; // D-202: optional app, off by default; the owner can open it to turn it on
        }

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
