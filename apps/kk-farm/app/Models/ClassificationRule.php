<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-234: gender × age stage × purpose ('*' = any) → goat category and default female status. */
class ClassificationRule extends Model
{
    protected $guarded = [];
}
