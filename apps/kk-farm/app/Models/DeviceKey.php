<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceKey extends Model
{
    protected $guarded = [];

    protected $casts = ['revoked_at' => 'datetime'];
}
