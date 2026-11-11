<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-189: one version of an SOP (append-only). */
class SopRevision extends Model
{
    protected $guarded = [];

    protected $casts = ['approved_at' => 'datetime'];

    public function sop()
    {
        return $this->belongsTo(Sop::class);
    }
}
