<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\AnimalMovement;
use App\Models\HealthCheckup;
use App\Models\HeightRecord;
use App\Models\WeightRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-270: field-work reports: on each date, how many goats were weighed, temperature-checked, measured or moved, with
 * the day's numbers; open a date to see every goat. Temperatures only for the roles that see health (RBAC §7).
 */
class RecordsReportController extends Controller
{
    public const KINDS = ['weight' => 'Weighings', 'temp' => 'Temperatures', 'height' => 'Heights', 'move' => 'Movements'];

    private static function seesHealth($u): bool
    {
        return in_array($u->role, ['ceo', 'manager', 'vet'], true) || ($u->module_access['health'] ?? false) === true;
    }

    private static function kind(Request $request): string
    {
        $k = (string) $request->query('kind', 'weight');
        abort_unless(isset(self::KINDS[$k]), 404);
        abort_if($k === 'temp' && ! self::seesHealth($request->user()), 403);

        return $k;
    }

    public function index(Request $request): Response
    {
        $kind = self::kind($request);

        return Inertia::render('Reports/Records', [
            'kind' => $kind, 'kinds' => collect(self::KINDS)->when(! self::seesHealth($request->user()), fn ($c) => $c->except('temp')),
            'rows' => $this->rows($kind)->sortByDesc('on')->values(), // D-273: every record with its goat's details, for the grid's filters
        ]);
    }

    /** One date's goats, opened from the report. */
    public function day(Request $request): JsonResponse
    {
        $kind = self::kind($request);
        $on = $request->validate(['on' => ['required', 'date']])['on'];

        return response()->json(['rows' => $this->rows($kind)->filter(fn ($r) => $r['on'] === $on)->sortBy('number')->values()]);
    }

    /** Every record of this kind, one row per goat per date, with the change since that goat's record before. */
    private function rows(string $kind): Collection
    {
        $numbers = Animal::pluck('farm_number', 'id');
        // D-273: the goat's details on every record, so the report can be filtered by any of them
        $goat = Animal::with(['breed', 'pen'])->get()->mapWithKeys(fn (Animal $a) => [$a->id => [
            'breed' => $a->breed?->name, 'sex' => $a->sex === 'female' ? 'Female' : ($a->sex === 'male' ? 'Male' : null), 'pen' => $a->pen?->name,
            'role' => \App\Support\Designations::main($a), 'code' => $a->classCode(), 'status' => $a->status === 'active' ? 'On farm' : 'Left',
        ]]);
        $with = fn (Collection $rows) => $rows->map(fn ($r) => $r + ($goat[$r['goatId']] ?? []));
        $series = function (Collection $recs, string $date, callable $value, callable $by, ?callable $extra = null) use ($numbers) {
            return $recs->groupBy('animal_id')->flatMap(function ($list) use ($date, $value, $by, $extra, $numbers) {
                $prev = null;

                return $list->sortBy(fn ($r) => [$r->{$date}->format('Y-m-d'), $r->id])->map(function ($r) use (&$prev, $date, $value, $by, $extra, $numbers) {
                    $v = $value($r);
                    $row = ['on' => $r->{$date}->format('Y-m-d'), 'goatId' => $r->animal_id, 'number' => $numbers[$r->animal_id] ?? '?', 'value' => $v,
                        'change' => $prev !== null && $v !== null ? round($v - $prev[0], 1) : null, 'days' => $prev ? max(1, (int) $prev[1]->diffInDays($r->{$date})) : null, 'by' => $by($r)] + ($extra ? $extra($r) : []);
                    if ($v !== null) {
                        $prev = [$v, $r->{$date}];
                    }

                    return $row;
                });
            });
        };

        return $with(match ($kind) {
            'weight' => $series(WeightRecord::orderBy('measured_on')->get(), 'measured_on', fn ($w) => (float) $w->weight_kg, fn ($w) => $w->measured_by, fn ($w) => ['bcs' => $w->body_score]),
            'height' => $series(HeightRecord::get()->concat(WeightRecord::whereNotNull('height_cm')->get()), 'measured_on', fn ($h) => round((float) $h->height_cm / 2.54, 1), fn ($h) => $h->measured_by),
            'temp' => $series(HealthCheckup::whereNotNull('temperature_f')->get(), 'checked_on', fn ($c) => (float) $c->temperature_f, fn ($c) => $c->examiner, function ($c) {
                [$low, $high] = HealthCheckup::NORMAL['temperature_f'];

                return ['fever' => $c->temperature_f < $low || $c->temperature_f > $high, 'result' => HealthCheckup::OUTCOMES[$c->outcome] ?? $c->outcome];
            }),
            'move' => AnimalMovement::with(['fromPen', 'toPen'])->where('type', 'transfer')->get()->map(fn ($m) => ['on' => $m->moved_on->format('Y-m-d'), 'goatId' => $m->animal_id,
                'number' => $numbers[$m->animal_id] ?? '?', 'value' => null, 'change' => null, 'days' => null, 'by' => $m->authorized_by, 'from' => $m->fromPen?->name, 'to' => $m->toPen?->name, 'reason' => $m->reason]),
        });
    }
}
