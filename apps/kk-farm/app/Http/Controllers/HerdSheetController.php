<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Support\Designations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-288: the Herd Sheet: one spreadsheet with every goat (on the farm and left) and everything known about it, so the
 * farm reports from one place: filter on anything, group by anything, export. Built from grouped queries (no per-goat
 * queries). Health columns only for the roles that see health (RBAC §7).
 */
class HerdSheetController extends Controller
{
    public function index(Request $request): Response
    {
        $u = $request->user();
        $seeHealth = $u->passes('see_health_history');
        $d = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : null;
        $iso = fn ($v) => $v ? substr((string) $v, 0, 10) : null;
        $by = fn (string $sql, string $key = 'animal_id') => collect(DB::select($sql))->keyBy($key);

        $weights = $by("select animal_id, count(*) n,
            (array_agg(weight_kg order by measured_on, id))[1] first_kg, (array_agg(measured_on order by measured_on, id))[1] first_on,
            (array_agg(weight_kg order by measured_on desc, id desc))[1] last_kg, (array_agg(measured_on order by measured_on desc, id desc))[1] last_on,
            (array_agg(body_score order by measured_on desc, id desc) filter (where body_score is not null))[1] bcs
            from weight_records group by animal_id");
        $heights = $by("select animal_id, (array_agg(height_cm order by measured_on desc, id desc))[1] cm, (array_agg(measured_on order by measured_on desc, id desc))[1] on_ from height_records group by animal_id");
        $checks = $seeHealth ? $by("select animal_id, count(*) n, (array_agg(temperature_f order by checked_on desc, id desc) filter (where temperature_f is not null))[1] temp,
            (array_agg(checked_on order by checked_on desc, id desc))[1] last_on, (array_agg(outcome order by checked_on desc, id desc))[1] outcome from health_checkups group by animal_id") : collect();
        $cases = $seeHealth ? $by("select animal_id, count(*) n, count(*) filter (where status in ('".implode("','", \App\Models\HealthCase::ACTIVE)."')) open_n,
            (array_agg(condition order by onset_on desc, id desc))[1] last_condition from health_cases group by animal_id") : collect();
        $treats = $seeHealth ? $by("select animal_id, count(*) n, (array_agg(product order by given_on desc, id desc))[1] last_product, max(given_on) last_on from treatments group by animal_id") : collect();
        $withdrawal = $seeHealth ? \App\Support\Withdrawal::active() : collect();
        $kids = $by("select dam_id, count(*) n from animals where dam_id is not null and voided_at is null group by dam_id", 'dam_id');
        $sired = $by("select sire_id, count(*) n from animals where sire_id is not null and voided_at is null group by sire_id", 'sire_id');
        $kiddings = $by("select dam_id, count(*) n, coalesce(sum(born_alive),0) alive, coalesce(sum(stillborn),0) still, max(kidded_on) last_on from kiddings group by dam_id", 'dam_id');
        $matDam = $by("select dam_id, count(*) n, max(mated_on) last_on from matings group by dam_id", 'dam_id');
        $matSire = $by("select sire_id, count(*) n from matings where sire_id is not null group by sire_id", 'sire_id');
        $moves = $by("select animal_id, count(*) filter (where type = 'transfer') n from animal_movements group by animal_id");
        $exits = $by("select distinct on (m.animal_id) m.animal_id, m.type, m.destination, m.amount_minor, m.sale_reason, coalesce(d.name, m.sale_reason_note) sale_detail from animal_movements m left join diseases d on d.id = m.disease_id where m.type <> 'transfer' order by m.animal_id, m.moved_on desc, m.id desc");
        $tags = $by("select a.animal_id, t.tag_code from rfid_assignments a join rfid_tags t on t.id = a.rfid_tag_id where a.valid_to is null");
        $entered = $by("select distinct on (animal_id) animal_id, recorded_by from animal_events where type = 'origin' order by animal_id, occurred_at");
        $numbers = Animal::pluck('farm_number', 'id');
        $origin = ['purchased' => 'Purchased', 'transferred_in' => 'Transferred in', 'born_on_farm' => 'Born on the farm'];
        $health = ['healthy' => 'Healthy', 'monitor' => 'Watch', 'sick' => 'In treatment', 'critical' => 'Critical', 'quarantine' => 'Quarantine'];

        $rows = Animal::with(['breed', 'pen.shed'])->whereNull('voided_at')->orderBy('farm_number')->get()->map(function (Animal $a) use ($d, $iso, $weights, $heights, $checks, $cases, $treats, $withdrawal, $kids, $sired, $kiddings, $matDam, $matSire, $moves, $exits, $tags, $entered, $numbers, $origin, $health, $seeHealth) {
            $w = $weights[$a->id] ?? null; $h = $heights[$a->id] ?? null; $c = $checks[$a->id] ?? null; $hc = $cases[$a->id] ?? null; $t = $treats[$a->id] ?? null;
            $k = $kiddings[$a->id] ?? null; $md = $matDam[$a->id] ?? null; $ex = $exits[$a->id] ?? null; $wd = $withdrawal[$a->id] ?? null;
            $gain = $w && $w->n > 1 ? round($w->last_kg - $w->first_kg, 1) : null;
            $days = $w && $w->n > 1 ? max(1, (int) \Illuminate\Support\Carbon::parse($w->first_on)->diffInDays($w->last_on)) : null;
            $arrived = $a->origin === 'born_on_farm' ? $a->birth_date : $a->acquired_on;

            return [
                'id' => $a->id, 'tag' => $a->farm_number, 'name' => $a->name, 'rfid' => $tags[$a->id]->tag_code ?? null,
                'breed' => $a->breed?->name, 'sex' => $a->sex === 'female' ? 'Female' : ($a->sex === 'male' ? 'Male' : null),
                'origin' => $origin[$a->origin] ?? $a->origin, 'arrivedIso' => $iso($arrived), 'arrived' => $d($arrived), 'from' => $a->source_farm,
                'bornIso' => $iso($a->birth_date), 'born' => $d($a->birth_date), 'bornEstimated' => $a->birth_date_is_estimate ? 'Yes' : 'No',
                'ageMonths' => $a->birth_date ? (int) $a->birth_date->diffInMonths($a->exit_date ?? now()) : null,
                'code' => $a->classCode(), 'stage' => $a->ageStageCode(), 'role' => Designations::main($a), 'purpose' => $a->animal_purpose ? ucfirst($a->animal_purpose) : null,
                'shed' => $a->pen?->shed?->name, 'pen' => $a->pen?->name,
                'status' => $a->status === 'active' ? 'On farm' : 'Left', 'health' => $health[$a->health_flag] ?? ($a->health_flag ? ucfirst($a->health_flag) : null),
                'repro' => $a->repro_status ? ucfirst(str_replace('_', ' ', $a->repro_status)) : null,
                'weighings' => $w?->n ? (int) $w->n : 0, 'firstKg' => $w ? (float) $w->first_kg : null, 'latestKg' => $w ? (float) $w->last_kg : null,
                'latestOnIso' => $iso($w?->last_on), 'latestOn' => $d($w?->last_on), 'gainKg' => $gain, 'adg' => $gain !== null ? (int) round($gain * 1000 / $days) : null,
                'bcs' => $w?->bcs !== null && $w !== null ? (float) $w->bcs : null, 'heightIn' => $h ? round($h->cm / 2.54, 1) : null,
                'checkups' => $seeHealth ? (int) ($c->n ?? 0) : null, 'tempF' => $c?->temp !== null && $c ? (float) $c->temp : null, 'lastCheckIso' => $iso($c?->last_on), 'lastCheck' => $d($c?->last_on),
                'cases' => $seeHealth ? (int) ($hc->n ?? 0) : null, 'openCases' => $seeHealth ? (int) ($hc->open_n ?? 0) : null, 'lastCondition' => $hc->last_condition ?? null,
                'treatments' => $seeHealth ? (int) ($t->n ?? 0) : null, 'lastMedicine' => $t->last_product ?? null,
                'meatClear' => $d($wd['meat'] ?? null), 'milkClear' => $d($wd['milk'] ?? null),
                'dam' => $a->dam_id ? ($numbers[$a->dam_id] ?? null) : null, 'damId' => $a->dam_id, 'sire' => $a->sire_id ? ($numbers[$a->sire_id] ?? null) : null, 'sireId' => $a->sire_id,
                'kids' => $a->sex === 'female' ? (int) ($kids[$a->id]->n ?? 0) : (int) ($sired[$a->id]->n ?? 0),
                'kiddings' => $a->sex === 'female' ? (int) ($k->n ?? 0) : null, 'bornAlive' => $a->sex === 'female' ? (int) ($k->alive ?? 0) : null, 'stillborn' => $a->sex === 'female' ? (int) ($k->still ?? 0) : null,
                'lastKiddingIso' => $iso($k?->last_on), 'lastKidding' => $d($k?->last_on),
                'matings' => $a->sex === 'female' ? (int) ($md->n ?? 0) : (int) ($matSire[$a->id]->n ?? 0),
                'penMoves' => (int) ($moves[$a->id]->n ?? 0),
                'exitIso' => $iso($a->exit_date), 'exitOn' => $d($a->exit_date), 'exitReason' => $a->exit_reason ? ucfirst($a->exit_reason) : null,
                'buyer' => $ex->destination ?? null, 'saleReason' => isset($ex->sale_reason) ? (\App\Support\Movements::SALE_REASONS[$ex->sale_reason] ?? $ex->sale_reason) : null, 'saleDetail' => $ex->sale_detail ?? null, 'salePkr' => $ex && $ex->amount_minor !== null ? (int) round($ex->amount_minor / 100) : null,
                'enteredIso' => $iso($a->created_at?->setTimezone('Asia/Karachi')), 'entered' => $a->created_at?->setTimezone('Asia/Karachi')->format('d/m/Y g:i A'), 'enteredBy' => $entered[$a->id]->recorded_by ?? null,
            ];
        });

        return Inertia::render('Reports/Sheet', ['rows' => $rows->values(), 'seeHealth' => $seeHealth]);
    }
}
