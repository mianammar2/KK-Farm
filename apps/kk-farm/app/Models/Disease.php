<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-174: a disease or condition in the farm's disease master (Veterinary → Configuration). */
class Disease extends Model
{
    protected $guarded = [];

    protected $casts = ['zoonotic' => 'boolean', 'notifiable' => 'boolean', 'active' => 'boolean'];

    public const CATEGORIES = [
        'infectious' => 'Infectious', 'respiratory' => 'Respiratory', 'digestive' => 'Digestive', 'parasitic' => 'Parasitic', 'reproductive' => 'Reproductive',
        'neonatal' => 'Newborn', 'metabolic' => 'Metabolic / nutritional', 'toxic' => 'Poisoning', 'injury' => 'Injury', 'general' => 'General',
    ];

    /** The condition names offered in forms: the active diseases, or the built-in list for a farm that has none. @return list<string> */
    public static function names(): array
    {
        $names = self::where('active', true)->orderBy('name')->pluck('name')->all();

        return $names ?: HealthCase::CONDITIONS;
    }

    /** D-292: the symptoms associated with this disease (Symptom Master). */
    public function symptoms()
    {
        return $this->belongsToMany(Symptom::class, 'disease_symptom');
    }

}
