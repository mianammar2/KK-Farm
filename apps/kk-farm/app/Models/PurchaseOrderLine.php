<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-178 */
class PurchaseOrderLine extends Model
{
    protected $guarded = [];

    protected $casts = ['qty' => 'float', 'rate_minor' => 'integer', 'expiry_on' => 'date'];

    public const KINDS = ['feed' => 'Feed', 'medicine' => 'Medicine', 'store' => 'General Store'];
}
