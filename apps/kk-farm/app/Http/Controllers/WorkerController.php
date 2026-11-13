<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Pen;
use App\Models\Task;
use App\Models\WeightRecord;
use App\Models\HealthRecord;
use App\Support\Farm;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-179: the worker app for the RFID gun. A worker picks a job once (weigh, health check, move to a pen, look up)
 * and then scans goat after goat: each scan opens /scan as always, which sends the goat straight into that job's
 * quick form here. Forms post to the same endpoints as the full screens, so every record is the same record.
 */
class WorkerController extends Controller
{
    public const SESSION = 'worker_job';

    public const HOURS = 8; // a job stays chosen for a working shift

    /** job => [label, module needed, right needed (D-255: each job is a tick box in the access screens)] */
    public const JOBS = [
        'weigh' => ['Weigh Goats', 'weights', 'record_weights'],
        'temp' => ['Temp Check', 'health', 'health_checkup'],
        'health' => ['Health Round', 'health', 'report_concern'],
        'heat' => ['Heat Detection', 'breeding', 'record_heat'],
        'mating' => ['Record Mating', 'breeding', 'record_mating'],
        'milk' => ['Milk Recording', 'milking', 'record_milk'],
        'care' => ['Vaccinate / Deworm', 'health', 'give_care'],
        'move' => ['Move to a Pen', 'sheds', 'move_goats'],
        'lookup' => ['Look Up Goats', 'goats', null],
    ];

    /** May this person do this job? (its app and its right) */
    public static function allowed(\App\Models\User $u, string $job): bool
    {
        [$label, $module, $gate] = self::JOBS[$job];

        return $u->canModule($module) && ($gate === null || $u->passes($gate));
    }

    public static function job(Request $request): ?array
    {
        $job = $request->session()->get(self::SESSION);
        if (! $job || now()->timestamp - ($job['at'] ?? 0) > self::HOURS * 3600 || ! isset(self::JOBS[$job['job'] ?? ''])) {
            return null;
        }

        return $job;
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $job = self::job($request);
        $goat = $request->query('goat') ? Animal::with(['breed', 'pen.shed', 'latestWeight'])->find($request->query('goat')) : null;
        $pens = Pen::with('shed')->where('kind', 'pen')->orderBy('name')->get()->sortBy(fn (Pen $p) => $p->fullName())->values();

        return Inertia::render('Worker', [
            'jobs' => collect(self::JOBS)->filter(fn ($j, $k) => self::allowed($user, $k))->map(fn ($j) => $j[0]), // D-252, D-255
            'job' => $job ? ['job' => $job['job'], 'label' => self::JOBS[$job['job']][0], 'penId' => $job['pen'] ?? null, 'pen' => isset($job['pen']) ? Pen::with('shed')->find($job['pen'])?->fullName() : null, 'done' => $job['done'] ?? 0,
                'opts' => ($job['opts'] ?? []) + ['sire' => ! empty($job['opts']['sire_id']) ? Animal::find($job['opts']['sire_id'])?->farm_number : null, 'medicine' => ! empty($job['opts']['medicine_id']) ? \App\Models\Medicine::find($job['opts']['medicine_id'])?->name : null]] : null,
            // D-255: what the new jobs need to be set up
            'options' => [
                'bucks' => Animal::where('status', 'active')->where('sex', 'male')->whereNull('castrated_on')->orderBy('farm_number')->get(['id', 'farm_number', 'name'])->map(fn ($a) => ['id' => $a->id, 'name' => $a->farm_number.($a->name ? " · {$a->name}" : '')]),
                'methods' => \App\Models\Mating::METHODS, 'sessions' => \App\Models\MilkRecord::SESSIONS, 'careKinds' => HealthController::CARE_TYPES,
                'medicines' => \App\Models\Medicine::where('active', true)->orderBy('name')->get(['id', 'name', 'unit'])->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'unit' => $m->unit]),
                'intensity' => \App\Models\HeatRecord::INTENSITY, 'signs' => \App\Models\HeatRecord::SIGNS,
            ],
            'goat' => $goat ? $goat->card() + [
                'lastWeight' => $goat->latestWeight?->weight_kg, 'lastWeighed' => $goat->latestWeight?->measured_on?->format('j M Y'), 'penId' => $goat->pen_id,
                'withdrawal' => \App\Support\Withdrawal::summary($goat),
            ] : null,
            'unknown' => $request->query('unknown'),
            'pens' => $pens->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName()]),
            'can' => ['feed' => $user->canModule('feeding'), 'tasks' => $user->canModule('tasks')],
            'myTasks' => $user->canModule('tasks') ? Task::where('status', '!=', 'completed')->where('status', '!=', 'cancelled')->where(fn ($q) => $q->where('user_id', $user->id)->orWhere(fn ($q) => $q->whereNull('user_id')->where('department_id', $user->department_id)))->whereDate('due_on', '<=', today())->count() : 0,
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'job' => ['required', Rule::in(array_keys(self::JOBS))], 'pen_id' => ['nullable', 'required_if:job,move', 'integer', 'exists:pens,id'],
            // D-255: what the job needs, chosen once for the whole round
            'reason' => ['nullable', 'required_if:job,move', 'string', 'max:120'],
            'sire_id' => ['nullable', 'exists:animals,id'], 'method' => ['nullable', 'required_if:job,mating', Rule::in(array_keys(\App\Models\Mating::METHODS))],
            'session' => ['nullable', 'required_if:job,milk', Rule::in(array_keys(\App\Models\MilkRecord::SESSIONS))],
            'care_kind' => ['nullable', 'required_if:job,care', Rule::in(array_keys(HealthController::CARE_TYPES))],
            'medicine_id' => ['nullable', 'exists:medicines,id'], 'qty_per_goat' => ['nullable', 'required_with:medicine_id', 'numeric', 'gt:0', 'max:1000'],
            'care_title' => ['nullable', 'string', 'max:120'],
            'on' => ['nullable', 'date', 'before_or_equal:today'], // D-255: the day the round's records are for
        ], ['reason.required_if' => 'Say why the goats are moved.', 'session.required_if' => 'Choose the milking session.', 'care_kind.required_if' => 'Choose what is given.'], ['pen_id' => 'pen']);
        abort_unless(self::allowed($request->user(), $data['job']), 403); // D-241, D-252, D-255
        if ($data['job'] === 'care' && empty($data['medicine_id']) && empty($data['care_title'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['care_title' => 'Choose the medicine from the store, or type what is given.']);
        }
        $opts = array_filter(\Illuminate\Support\Arr::only($data, ['reason', 'sire_id', 'method', 'session', 'care_kind', 'medicine_id', 'qty_per_goat', 'care_title', 'on']), fn ($v) => $v !== null && $v !== '');
        $request->session()->put(self::SESSION, ['job' => $data['job'], 'pen' => $data['pen_id'] ?? null, 'opts' => $opts, 'at' => now()->timestamp, 'done' => 0]);

        return redirect('/worker')->with('success', self::JOBS[$data['job']][0].': now scan the first goat.');
    }

    /** D-255: a goat finished in a job that saves through the app's own form (temp, heat, mating, milk, care): count it, next goat. */
    public function done(Request $request): RedirectResponse
    {
        abort_unless(self::job($request), 422, 'No job is running.');
        self::tick($request);

        return redirect('/worker')->with('success', 'Saved. Scan the next goat.');
    }

    public function stop(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION);

        return redirect('/worker');
    }

    /** Counts a finished goat in the current job and returns to scanning. */
    public static function tick(Request $request): void
    {
        if ($job = self::job($request)) {
            $job['done'] = ($job['done'] ?? 0) + 1;
            $request->session()->put(self::SESSION, $job);
        }
    }

    public const CONCERNS = ['Not eating', 'Limping', 'Coughing', 'Diarrhoea', 'Fever / hot', 'Weak / dull', 'Wound', 'Nasal discharge', 'Swollen udder', 'Other'];

    private function goat(Request $request): Animal
    {
        $animal = Animal::findOrFail($request->validate(['animal_id' => ['required', 'exists:animals,id']])['animal_id']);
        abort_unless($animal->status === 'active', 422, 'This goat is no longer on the farm.');

        return $animal;
    }

    /** Weigh: the same weight record as Weight & Growth. */
    public function weigh(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('record_weights'), 403); // D-241
        $animal = $this->goat($request);
        \App\Support\WelfareCare::assertRoutineAllowed($animal); // D-199
        $data = $request->validate(['weight_kg' => ['required', 'numeric', 'min:0.5', 'max:200'], 'body_score' => ['nullable', 'integer', 'between:1,5']], [], ['weight_kg' => 'weight']);
        $on = self::job($request)['opts']['on'] ?? now()->toDateString(); // D-255: the round's date
        $w = WeightRecord::create(['animal_id' => $animal->id, 'weight_kg' => $data['weight_kg'], 'measured_on' => $on, 'measured_by' => $request->user()->name, 'body_score' => $data['body_score'] ?? null]);
        Farm::event($animal, 'weight', "Weighed {$data['weight_kg']} KG".(! empty($data['body_score']) ? ", body score {$data['body_score']}" : '').' (RFID gun)', $request->user()->name, \Illuminate\Support\Carbon::parse($on));
        Farm::log($request->user(), "Weighed {$animal->farm_number} at {$data['weight_kg']} KG", "/goats/{$animal->id}");
        self::tick($request);

        return redirect('/worker')->with('success', "{$animal->farm_number}: {$data['weight_kg']} KG saved".($w->review_status === 'held' ? ' but held for review (looks wrong: weigh again if you can)' : '').'. Scan the next goat.');
    }

    /** Health check on a round: looks fine (noted on its lifecycle), or a concern for the vet (the same as Report a Concern). */
    public function health(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canModule('health') && $request->user()->passes('report_concern'), 403); // D-252
        $animal = $this->goat($request);
        $data = $request->validate(['fine' => ['required', 'boolean'], 'title' => ['nullable', 'required_if:fine,false', 'string', 'max:120'], 'severity' => ['nullable', 'required_if:fine,false', Rule::in(['low', 'medium', 'high'])], 'notes' => ['nullable', 'string', 'max:500']], [], ['title' => 'what you noticed']);
        if ($data['fine']) {
            Farm::event($animal, 'health', 'Checked on the health round: looks fine', $request->user()->name);
            self::tick($request);

            return redirect('/worker')->with('success', "{$animal->farm_number} looks fine. Scan the next goat.");
        }
        DB::transaction(function () use ($animal, $data, $request) {
            HealthRecord::create(['animal_id' => $animal->id, 'title' => $data['title'], 'severity' => $data['severity'], 'notes' => $data['notes'] ?? null, 'kind' => 'observation', 'occurred_on' => self::job($request)['opts']['on'] ?? now(), 'recorded_by' => $request->user()->name, 'recorded_role' => $request->user()->roleLabel(), 'status' => 'open']);
            if ($animal->health_status === 'healthy') {
                $animal->update(['health_status' => 'watch']);
            }
            Farm::event($animal, 'health', "Concern reported: {$data['title']}", $request->user()->name);
        });
        Farm::log($request->user(), "Reported a concern for {$animal->farm_number}", "/goats/{$animal->id}");
        self::tick($request);

        return redirect('/worker')->with('success', "Concern for {$animal->farm_number} sent to the vet. Scan the next goat.");
    }

    /** Move: the goat goes to the pen chosen for the job (the same pen transfer as Movements). */
    public function move(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canModule('sheds') && $request->user()->passes('move_goats'), 403); // D-255
        $animal = Animal::with('pen.shed')->findOrFail($this->goat($request)->id);
        \App\Support\WelfareCare::assertRoutineAllowed($animal); // D-199
        $job = self::job($request);
        $pen = Pen::with('shed')->findOrFail($request->validate(['pen_id' => ['nullable', 'integer', 'exists:pens,id']])['pen_id'] ?? $job['pen'] ?? 0);
        $moved = \App\Support\Movements::transfer($animal, $pen, $request->user(), ['reason' => $job['opts']['reason'] ?? null, 'moved_on' => $job['opts']['on'] ?? null]); // D-252, D-255: the round's reason and date
        if ($moved) {
            Farm::log($request->user(), "Moved {$animal->farm_number} to {$pen->fullName()}", "/goats/{$animal->id}");
            self::tick($request);
        }

        return redirect('/worker')->with('success', $moved ? "{$animal->farm_number} moved to {$pen->fullName()}. Scan the next goat." : "{$animal->farm_number} is already in {$pen->fullName()}.");
    }
}
