<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-099: one correction of a record; never changed or deleted. */
class RecordRevision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['changes' => 'array'];
}
