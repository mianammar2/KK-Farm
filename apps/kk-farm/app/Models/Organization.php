<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A customer (tenant). Everything tenant-owned carries its id and is isolated by Row-Level Security (D-037). */
class Organization extends Model
{
    use HasUuids;

    protected $guarded = [];
}
