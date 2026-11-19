<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** D-354: one installed KK Farm gun app, known by its random install id. */
class GunDevice extends Model
{
    protected $guarded = [];

    protected $casts = ['state' => 'array', 'last_seen_at' => 'datetime', 'logs_at' => 'datetime'];

    public function commands(): HasMany
    {
        return $this->hasMany(GunCommand::class);
    }
}
