<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Shed extends Model
{
    protected $guarded = [];

    /** D-056: KK Farms shed (unit) types. code => [unit name, operational purpose]. */
    public const TYPES = [
        'BBU' => ['Breeder Buck Unit', 'Male goats selected for breeding'],
        'BDU' => ['Breeder Doe Unit', 'Female goats selected for breeding'],
        'RPU' => ['Reproduction Unit', 'Controlled mating and breeding'],
        'GTU' => ['Gestation Unit', 'Pregnant females'],
        'MTU' => ['Maternity Unit', 'Kidding and immediate postnatal care'],
        'LTU' => ['Lactation Unit', 'Nursing mothers with kids'],
        'NCU' => ['Neonatal Care Unit', 'Newborn kids requiring special care'],
        'KDU' => ['Kid Development Unit', 'Suckling and pre-weaning kids'],
        'WNU' => ['Weaning Unit', 'Kids undergoing weaning'],
        'GDU' => ['Grower Development Unit', 'Growing male and female goats'],
        'GND' => ['Genetic Development Unit', 'Selected future breeding stock'],
        'FNU' => ['Finishing Unit', 'Goats raised for weight gain or market'],
        'BSU' => ['Biosecurity Unit', 'Newly arrived goats under quarantine'],
        'VCU' => ['Veterinary Care Unit', 'Sick, isolated, and recovering goats'],
        'LHU' => ['Livestock Holding Unit', 'Temporary holding and sorting'],
        'MRU' => ['Market Readiness Unit', 'Goats approved for sale and dispatch'],
    ];

    /** "KKF-BDU-01": the farm prefix, the unit code and the next free number for that unit type. */
    public static function nextName(string $prefix, string $type, ?int $ignoreId = null): string
    {
        $pattern = strtoupper("$prefix-$type-");
        $used = DB::table('sheds')->where('name', 'ilike', "$pattern%")->when($ignoreId, fn ($q) => $q->where('id', '<>', $ignoreId))->pluck('name')
            ->map(fn ($n) => (int) substr((string) $n, strlen($pattern)))->all();
        for ($n = 1; in_array($n, $used, true); $n++);

        return $pattern.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }

    public function typeName(): ?string
    {
        return self::TYPES[$this->type][0] ?? null;
    }

    public function purpose(): ?string
    {
        return self::TYPES[$this->type][1] ?? null;
    }

    public function pens()
    {
        return $this->hasMany(Pen::class);
    }
}
