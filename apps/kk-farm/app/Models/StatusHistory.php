<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-232: one change of one status of a goat, with the rule behind it. Append-only. */
class StatusHistory extends Model
{
    protected $table = 'status_history';

    protected $guarded = [];

    protected $casts = ['changed_at' => 'datetime'];
}
