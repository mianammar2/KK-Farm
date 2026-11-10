<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-178 */
class Customer extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public const TYPES = ['butcher' => 'Butcher', 'trader' => 'Livestock Trader', 'farmer' => 'Farmer / Breeder', 'individual' => 'Individual', 'other' => 'Other'];

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }
}
