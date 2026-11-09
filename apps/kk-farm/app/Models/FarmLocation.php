<?php

namespace App\Models;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * D-128: a farm and where it is. Organization → Farm → Shed → Pen. Its code starts the names of its sheds (KKF-BDU-01).
 * Called FarmLocation in code because App\Support\Farm is the farm-wide helper; the table is `farms`.
 */
class FarmLocation extends Model
{
    protected $table = 'farms';

    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'latitude' => 'float', 'longitude' => 'float'];

    public function sheds()
    {
        return $this->hasMany(Shed::class, 'farm_id');
    }

    /** The organization's first farm, made from the shed prefix when there is none yet (a new organization). */
    public static function default(): self
    {
        return self::orderBy('id')->first() ?? self::create([
            'code' => strtoupper(Organization::whereKey(Tenant::current())->value('shed_prefix') ?: 'KKF'),
            'name' => Organization::whereKey(Tenant::current())->value('name') ?: 'Main farm',
            'created_by' => 'System',
        ]);
    }

    /** "Bahawalpur, Punjab, Pakistan" from what is filled in. */
    public function place(): string
    {
        return collect([$this->city, $this->district !== $this->city ? $this->district : null, $this->province, $this->country])->filter()->implode(', ');
    }
}
