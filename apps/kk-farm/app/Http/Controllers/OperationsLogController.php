<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-307: the Operations Log: every operation done on a goat, from every app, in one sheet: registered, weighed,
 * measured, moved, photo, details edited, RFID linked, exits; checkups, cases, treatments, doses, care, teeth, deaths;
 * heat, matings, ultrasounds, kiddings, weanings. Read from the goat's history (append-only), so nothing is typed twice.
 * Health operations only for those with the right to see health history (F-15).
 */
class OperationsLogController extends Controller
{
    /** operation => [app, label] in the order the filter buttons show */
    public const OPS = [
        'register' => ['Livestock', 'Registered'], 'weight' => ['Livestock', 'Weight'], 'height' => ['Livestock', 'Height'], 'move' => ['Livestock', 'PEN move'],
        'photo' => ['Livestock', 'Photo'], 'edit' => ['Livestock', 'Details edited'], 'rfid' => ['Livestock', 'RFID linked'], 'exit' => ['Livestock', 'Exit'],
        'checkup' => ['Veterinary', 'Checkup'], 'case' => ['Veterinary', 'Health case'], 'treatment' => ['Veterinary', 'Treatment'], 'dose' => ['Veterinary', 'Dose'],
        'care' => ['Veterinary', 'Vaccination / deworming'], 'teeth' => ['Veterinary', 'Teeth check'], 'death' => ['Veterinary', 'Death report'],
        'heat' => ['Breeding', 'Heat'], 'mating' => ['Breeding', 'Mating'], 'ultrasound' => ['Breeding', 'Ultrasound'], 'kidding' => ['Maternity', 'Kidding'], 'weaning' => ['Maternity', 'Weaning'],
    ];

    private const HEALTH = ['checkup', 'case', 'treatment', 'dose', 'care', 'teeth', 'death'];

    public function index(Request $request): Response
    {
        $health = $request->user()->passes('see_health_history');
        $ops = collect(self::OPS)->when(! $health, fn ($c) => $c->except(self::HEALTH));

        return Inertia::render('Reports/Operations', [
            'rows' => $this->rows($health)->sortByDesc('sort')->values(),
            'ops' => $ops->map(fn ($o, $k) => ['key' => $k, 'app' => $o[0], 'label' => $o[1]])->values(),
        ]);
    }

    private function rows(bool $health): Collection
    {
        $goats = Animal::with(['breed', 'pen'])->get()->keyBy('id');
        $tz = 'Asia/Karachi';
        $out = collect();
        // $on: the day the work was done; $at: when it was entered (for the time and order)
        $add = function (string $op, string $key, ?string $goatId, $on, $at, ?string $detail, ?string $by, ?string $source = null) use (&$out, $goats, $tz) {
            if (! $on) {
                return;
            }
            $day = $on instanceof Carbon ? $on->copy()->setTimezone($tz)->toDateString() : Carbon::parse($on)->toDateString();
            $entered = $at ? Carbon::parse($at)->setTimezone($tz) : null;
            $g = $goatId ? $goats[$goatId] ?? null : null;
            $out->push([
                'key' => "$op-$key", 'op' => $op, 'app' => self::OPS[$op][0], 'operation' => self::OPS[$op][1], 'on' => $day,
                'entered' => $entered?->format('d/m/Y g:i A'), 'sort' => $day.' '.($entered?->format('H:i:s') ?? '00:00:00').' '.$key,
                'goatId' => $goatId, 'number' => $g?->farm_number, 'detail' => $detail, 'by' => $by, 'source' => $source ? ucfirst($source) : 'Manual',
                'breed' => $g?->breed?->name, 'sex' => $g ? ($g->sex === 'female' ? 'Female' : 'Male') : null, 'pen' => $g?->pen?->name,
                'status' => $g ? ($g->status === 'active' ? 'On farm' : 'Left') : null,
            ]);
        };
        $origin = ['purchased' => 'Purchased', 'transferred_in' => 'Transferred in', 'born_on_farm' => 'Born on the farm'];
        $regBy = \App\Models\AnimalEvent::where('type', 'origin')->get(['animal_id', 'recorded_by'])->keyBy('animal_id');
        foreach ($goats as $a) {
            if (! $a->voided_at) {
                $add('register', $a->id, $a->id, $a->created_at, $a->created_at, trim(($origin[$a->origin] ?? $a->origin).($a->source_farm ? " from {$a->source_farm}" : '')), $regBy[$a->id]->recorded_by ?? null);
            }
        }
        foreach (\App\Models\WeightRecord::get() as $r) {
            $add('weight', $r->id, $r->animal_id, $r->measured_on, $r->created_at, "{$r->weight_kg} KG".($r->body_score ? " · body score {$r->body_score}" : ''), $r->measured_by, $r->source);
        }
        foreach (\App\Models\HeightRecord::get() as $r) {
            $add('height', $r->id, $r->animal_id, $r->measured_on, $r->created_at, round($r->height_cm / 2.54, 1).' in', $r->measured_by, $r->source);
        }
        $pens = \App\Models\Pen::pluck('name', 'id');
        foreach (\App\Models\AnimalMovement::get() as $m) {
            $move = in_array($m->type, ['move', 'transfer', 'internal'], true) || ($m->from_pen_id && $m->to_pen_id);
            $add($move ? 'move' : 'exit', $m->id, $m->animal_id, $m->moved_on, $m->created_at,
                $move ? ($pens[$m->from_pen_id] ?? '?').' → '.($pens[$m->to_pen_id] ?? '?').($m->reason ? " · {$m->reason}" : '') : ucfirst(str_replace('_', ' ', $m->type)).($m->reason ? " · {$m->reason}" : ''),
                $m->recorded_by);
        }
        foreach (\App\Models\AnimalPhoto::get() as $p) {
            $add('photo', $p->id, $p->animal_id, $p->created_at, $p->created_at, $p->is_profile ? 'Profile photo' : ($p->notes ?: 'Photo'), $p->added_by);
        }
        foreach (\App\Models\AnimalEvent::where('type', 'edit')->get() as $e) { // details edited and records corrected (Revisions)
            $add('edit', $e->id, $e->animal_id, $e->occurred_at, $e->created_at, $e->summary, $e->recorded_by);
        }
        foreach (\App\Models\RfidAssignment::get() as $r) {
            $add('rfid', $r->id, $r->animal_id, $r->valid_from, $r->created_at, $r->reason_assigned ? ucfirst(str_replace('_', ' ', $r->reason_assigned)) : 'Tag linked', $r->assigned_by);
        }
        if ($health) {
            foreach (\App\Models\HealthCheckup::get() as $c) {
                $add('checkup', $c->id, $c->animal_id, $c->checked_on, $c->created_at, implode(' · ', array_filter([$c->temperature_f ? "{$c->temperature_f} °F" : null, $c->weight_kg ? "{$c->weight_kg} KG" : null, $c->outcome ? ucfirst($c->outcome) : null, $c->findings])), $c->examiner);
            }
            foreach (\App\Models\HealthCase::get() as $c) {
                $add('case', $c->id, $c->animal_id, $c->onset_on, $c->created_at, trim("Case H-{$c->case_no} · {$c->condition} · ".ucfirst((string) $c->severity).' · '.str_replace('_', ' ', (string) $c->status)), $c->recorded_by);
            }
            foreach (\App\Models\Treatment::get() as $t) {
                $add('treatment', $t->id, $t->animal_id, $t->given_on, $t->created_at, trim("{$t->product} · {$t->dose}".($t->days_prescribed ? " · {$t->days_prescribed} days" : '')), $t->recorded_by);
            }
            foreach (\App\Models\TreatmentDose::with('treatment')->where('status', '<>', 'due')->where('number', '>', 1)->get() as $d) {
                $add('dose', $d->id, $d->animal_id, $d->done_at ?? $d->due_on, $d->done_at, "{$d->treatment?->product} · dose {$d->number} · ".ucfirst($d->status).($d->adverse ? ' · reaction' : '').($d->notes ? " · {$d->notes}" : ''), $d->done_by);
            }
            foreach (\App\Models\HealthRecord::whereIn('kind', ['vaccination', 'deworming'])->get() as $h) {
                $add('care', $h->id, $h->animal_id, $h->occurred_on, $h->created_at, ucfirst($h->kind).' · '.$h->title, $h->recorded_by);
            }
            foreach (\App\Models\DentalExam::get() as $e) {
                $add('teeth', $e->id, $e->animal_id, $e->examined_on, $e->created_at, trim($e->dental_code.($e->condition ? ' · '.str_replace('_', ' ', $e->condition) : '')), $e->recorded_by, $e->source);
            }
            foreach (\App\Models\DeathReport::get() as $d) {
                $add('death', $d->id, $d->animal_id, $d->died_at, $d->created_at, trim("{$d->cause} · ".str_replace('_', ' ', (string) $d->status)), $d->reported_by);
            }
        }
        foreach (\App\Models\HeatRecord::get() as $h) {
            $add('heat', $h->id, $h->dam_id, $h->observed_on, $h->created_at, $h->intensity ? ucfirst($h->intensity) : null, $h->observed_by);
        }
        foreach (\App\Models\Mating::get() as $m) {
            $sire = $m->sire_id ? $goats[$m->sire_id]?->farm_number : null;
            $add('mating', $m->id, $m->dam_id, $m->mated_on, $m->created_at, trim('With '.($sire ?? 'unknown buck').' · '.str_replace('_', ' ', (string) $m->method)), $m->recorded_by, $m->source);
        }
        foreach (\App\Models\PregnancyCheck::get() as $c) {
            $add('ultrasound', $c->id, $c->dam_id, $c->checked_on, $c->created_at, trim(str_replace('_', ' ', (string) $c->result).($c->fetuses ? " · {$c->fetuses} fetus(es)" : '').($c->pregnancy_day ? " · day {$c->pregnancy_day}" : '')), $c->examiner);
        }
        foreach (\App\Models\Kidding::get() as $k) {
            $add('kidding', $k->id, $k->dam_id, $k->kidded_on, $k->created_at, "{$k->born_alive} alive".($k->stillborn ? " · {$k->stillborn} stillborn" : ''), $k->recorded_by);
        }
        foreach (\App\Models\Weaning::get() as $w) {
            $add('weaning', $w->id, $w->kid_id, $w->weaned_on ?? $w->created_at, $w->created_at, trim(ucfirst((string) $w->status).($w->weight_kg ? " · {$w->weight_kg} KG" : '')), $w->handler);
        }

        return $out;
    }
}
