<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-203: a welfare check during a trip. */
class TransportCheck extends Model
{
    protected $guarded = [];

    protected $casts = ['checked_at' => 'datetime', 'watered' => 'boolean'];
}
