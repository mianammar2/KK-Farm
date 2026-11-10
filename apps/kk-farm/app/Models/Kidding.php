<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kidding extends Model
{
    protected $guarded = [];

    protected $casts = ['kidded_on' => 'date', 'follow_up_on' => 'date', 'premature' => 'boolean'];

    /** D-161: KK Farms pregnancy outcome categories (ERP codes). */
    public const OUTCOMES = [
        'NKD' => 'Normal Kidding', 'AKD' => 'Assisted Kidding', 'CSD' => 'Cesarean Delivery', 'PKD' => 'Premature Kidding',
        'ABT' => 'Abortion / Pregnancy Loss', 'EEL' => 'Early Embryonic Loss', 'STB' => 'Stillbirth', 'MBO' => 'Mixed Birth Outcome', 'UNK' => 'Pregnancy Outcome Unknown',
    ];

    /** Outcomes where the pregnancy was lost before term: no kids, no lactation, vet alert and biosecurity task. */
    public const LOSSES = ['ABT', 'EEL'];

    /** Outcomes counted as a successful delivery (at least one live kid). */
    public const DELIVERIES = ['NKD', 'AKD', 'CSD', 'PKD', 'MBO'];

    public const METHODS = ['NRM' => 'Normal / Unassisted', 'AST' => 'Assisted Delivery', 'CSE' => 'Cesarean Section', 'NAP' => 'Not Applicable'];

    public const KID_OUTCOMES = ['LVB' => 'Live Born', 'STB' => 'Stillborn', 'BAW' => 'Born Alive – Weak', 'NDT' => 'Born Alive – Died Within 24 Hours', 'UNK' => 'Outcome Unknown'];

    public const MOTHER = [
        'HNR' => 'Healthy / Normal Recovery', 'OBS' => 'Under Observation', 'LCT' => 'Lactating / Nursing', 'RTP' => 'Retained Placenta',
        'PKC' => 'Post-Kidding Complication', 'PAR' => 'Post-Abortion Recovery', 'VTR' => 'Veterinary Treatment', 'DED' => 'Died',
    ];

    /** Mother statuses that need a vet review (retained placenta, complications, treatment, death). */
    public const VET_REVIEW = ['RTP', 'PKC', 'VTR', 'DED'];

    /** Mother statuses counted as a normal recovery on the dashboard. */
    public const RECOVERED = ['HNR', 'LCT'];

    /** D-162: days a doe stays under monitoring after a delivery or a pregnancy loss. */
    public const MONITOR_DAYS = 3;

    /** Pregnancy status of a mating (derived from it, never typed in). */
    public const PREGNANCY = [
        'PSU' => 'Pregnancy Suspected', 'PCF' => 'Pregnancy Confirmed', 'PMN' => 'Pregnancy Monitoring', 'NKP' => 'Near Kidding',
        'KIP' => 'Kidding in Progress', 'PCM' => 'Pregnancy Completed', 'PLS' => 'Pregnancy Loss',
    ];

    public const ASSISTED = ['no' => 'No', 'yes' => 'Yes', 'emergency' => 'Veterinary emergency'];

    /** Workbook litter type from the total born. */
    public static function litterType(int $total): string
    {
        return [0 => 'None', 1 => 'Single', 2 => 'Twin', 3 => 'Triplet', 4 => 'Quadruplet'][$total] ?? '5+';
    }

    /** D-117: a doe that kids is in milk: her lactation starts by itself. */
    protected static function booted(): void
    {
        static::created(function (self $k) {
            // D-161: a pregnancy lost before term does not start a lactation.
            if ($k->dam_id && ! in_array($k->outcome, self::LOSSES, true) && ($dam = Animal::find($k->dam_id))) {
                \App\Support\Milking::start($dam, $k->kidded_on, 'kidding', $k->recorded_by ?? 'System', $k);
            }
        });
    }

    /** D-161: the form's choices, shared by the Breeding page and the goat page. */
    public static function formOptions(): array
    {
        return ['outcomes' => self::OUTCOMES, 'deliveryMethods' => self::METHODS, 'mother' => self::MOTHER, 'losses' => self::LOSSES];
    }

    /**
     * D-161: reproduction KPIs for the owner: events, successful deliveries, abortion cases, stillborn and live kids,
     * kidding rate (live kids per doe that delivered), pregnancy loss rate and post-kidding recovery.
     */
    public static function kpis(): array
    {
        $all = self::query()->get(['outcome', 'born_alive', 'stillborn', 'mother_status']);
        $deliveries = $all->filter(fn ($k) => in_array($k->outcome, self::DELIVERIES, true) || (! $k->outcome && $k->born_alive > 0));
        $losses = $all->filter(fn ($k) => in_array($k->outcome, self::LOSSES, true));
        $delivered = $all->reject(fn ($k) => in_array($k->outcome, self::LOSSES, true));
        $withStatus = $delivered->filter(fn ($k) => $k->mother_status);

        return [
            'events' => $all->count(), 'deliveries' => $deliveries->count(), 'abortions' => $losses->count(),
            'stillborn' => (int) $all->sum('stillborn'), 'live' => (int) $all->sum('born_alive'),
            'kiddingRate' => $deliveries->count() ? round($all->sum('born_alive') / $deliveries->count(), 2) : null,
            'lossRate' => $all->count() ? round($losses->count() / $all->count() * 100, 1) : null,
            'recovery' => $withStatus->count() ? round($withStatus->filter(fn ($k) => in_array($k->mother_status, self::RECOVERED, true))->count() / $withStatus->count() * 100) : null,
        ];
    }

    public function kids()
    {
        return $this->hasMany(Animal::class, 'birth_kidding_id');
    }

    public function dam()
    {
        return $this->belongsTo(Animal::class, 'dam_id');
    }
}
