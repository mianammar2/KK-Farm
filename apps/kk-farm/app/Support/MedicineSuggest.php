<?php

namespace App\Support;

use App\Models\Disease;
use App\Models\HealthCase;
use App\Models\Treatment;
use Illuminate\Support\Facades\DB;

/**
 * D-333: which medicines to offer first. The system recommends; the veterinarian decides. Never a prescription.
 *
 * For a case of a disease, in this order: the medicines the vet set for that disease (disease_medicine), the medicines
 * the farm has already used on cases of that disease (most used first), then the medicine types that fit the disease's
 * category. For preventive care, the medicine types that fit the kind of care. Vet consumables and equipment are never
 * offered as treatments; products on hold are shown but cannot be given (MedicineStock::issue).
 */
final class MedicineSuggest
{
    /** Disease category → the medicine categories that usually fit it, closest first. */
    public const BY_DISEASE = [
        'infectious' => ['antibiotic', 'anti_inflammatory', 'supportive'],
        'respiratory' => ['antibiotic', 'anti_inflammatory', 'antihistamine', 'supportive'],
        'digestive' => ['digestive', 'antibiotic', 'fluids', 'supportive'],
        'parasitic' => ['dewormer', 'ectoparasiticide'],
        'reproductive' => ['antibiotic', 'anti_inflammatory', 'fluids', 'vitamin'],
        'neonatal' => ['vitamin', 'supportive', 'fluids', 'antibiotic'],
        'metabolic' => ['fluids', 'vitamin', 'supportive', 'digestive'],
        'toxic' => ['emergency', 'supportive', 'fluids'],
        'injury' => ['antiseptic', 'anti_inflammatory', 'anaesthetic', 'antibiotic'],
        'general' => ['anti_inflammatory', 'supportive', 'vitamin'],
    ];

    /** Kind of preventive care → the medicine categories that fit it. */
    public const BY_CARE = [
        'vaccination' => ['vaccine'], 'deworming' => ['dewormer', 'ectoparasiticide'], 'hoof_care' => ['antiseptic', 'ectoparasiticide'],
        'examination' => [], 'other_care' => ['vitamin', 'supportive'],
    ];

    /** Never offered as something given to a goat. */
    public const NOT_GIVEN = ['consumable', 'equipment'];

    /** What the pages need to rank medicines: the fit tables, each disease's category, set and used medicines. */
    public static function data(): array
    {
        $diseases = Disease::orderBy('name')->get(['id', 'name', 'category']);
        $set = DB::table('disease_medicine')->get(['disease_id', 'medicine_id'])->groupBy('disease_id')->map(fn ($g) => $g->pluck('medicine_id')->all());
        // treatments given on cases of each disease (a case without a disease id is matched by its condition name)
        $byName = $diseases->pluck('id', 'name');
        $used = Treatment::query()->join('health_cases', 'health_cases.id', '=', 'treatments.health_case_id')->whereNotNull('treatments.medicine_id')
            ->groupBy('health_cases.disease_id', 'health_cases.condition', 'treatments.medicine_id')
            ->selectRaw('health_cases.disease_id, health_cases.condition, treatments.medicine_id, count(*) as n')->get()
            ->reduce(function (array $acc, $r) use ($byName) {
                $d = $r->disease_id ?? ($byName[$r->condition] ?? null);
                if ($d) {
                    $acc[$d][$r->medicine_id] = ($acc[$d][$r->medicine_id] ?? 0) + (int) $r->n;
                }

                return $acc;
            }, []);

        return [
            'byDisease' => self::BY_DISEASE, 'byCare' => self::BY_CARE, 'notGiven' => self::NOT_GIVEN,
            'diseases' => $diseases->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'category' => $d->category, 'set' => $set[$d->id] ?? [], 'used' => (object) ($used[$d->id] ?? [])])->values()->all(),
        ];
    }

    /** The disease a case is about: its disease id, or the disease of the same name. */
    public static function diseaseOf(HealthCase $c): ?int
    {
        return $c->disease_id ?? Disease::where('name', $c->condition)->value('id');
    }
}
