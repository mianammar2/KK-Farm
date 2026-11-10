<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-176: one care action for a newborn kid. */
class KidCareRecord extends Model
{
    protected $guarded = [];

    protected $casts = ['given_at' => 'datetime'];

    public const KINDS = ['colostrum' => 'Colostrum', 'bottle_feed' => 'Bottle Feed', 'nursing_check' => 'Nursing Check', 'warming' => 'Warming', 'fostered' => 'Fostered to a Doe'];

    public const SOURCES = ['dam' => 'From its mother', 'other_doe' => 'From another doe', 'stored' => 'Stored colostrum', 'replacer' => 'Milk replacer'];

    public const SUCKLING = ['good' => 'Suckling well', 'weak' => 'Weak suckling', 'none' => 'Not suckling'];
}
