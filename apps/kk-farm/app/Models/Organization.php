<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A customer (tenant). Everything tenant-owned carries its id and is isolated by Row-Level Security (D-037). */
class Organization extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['meal_times' => 'array'];

    protected static function booted(): void
    {
        // A new organization starts with the standard goat registration fields (D-040).
        static::created(function (self $org): void {
            \App\Support\FieldCatalog::seed($org->id);
            Department::seedDefaults($org->id); // D-052
            \App\Support\Feed::seedGroups($org->id); // D-054
            \App\Support\Feed::seedStandards($org->id); // D-116
        });
    }
}
