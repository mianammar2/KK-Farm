<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-178 */
class Supplier extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];
}
