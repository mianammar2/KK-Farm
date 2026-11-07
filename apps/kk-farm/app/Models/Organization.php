<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A customer (tenant). Everything tenant-owned carries its id and is isolated by Row-Level Security (D-037). */
class Organization extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected static function booted(): void
    {
        // A new organization starts with the standard goat registration fields (D-040).
        static::created(fn (self $org) => \App\Support\FieldCatalog::seed($org->id));
    }
}
