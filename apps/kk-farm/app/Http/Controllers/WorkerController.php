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

    /** job => [label, module needed] */
    public const JOBS = ['weigh' => ['Weigh Goats', 'weights'], 'health' => ['Health Check', 'health'], 'move' => ['Move to a Pen', 'sheds'], 'lookup' => ['Look Up Goats', 'goats']];

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
            'jobs' => collect(self::JOBS)->filter(fn ($j) => $user->canModule($j[1]))->map(fn ($j) => $j[0]),
            'job' => $job ? ['job' => $job['job'], 'label' => self::JOBS[$job['job']][0], 'penId' => $job['pen'] ?? null, 'pen' => isset($job['pen']) ? Pen::with('shed')->find($job['pen'])?->fullName() : null, 'done' => $job['done'] ?? 0] : null,
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
        $data = $request->validate(['job' => ['required', Rule::in(array_keys(self::JOBS))], 'pen_id' => ['nullable', 'required_if:job,move', 'integer', 'exists:pens,id']], [], ['pen_id' => 'pen']);
        abort_unless($request->user()->canModule(self::JOBS[$data['job']][1]), 403);
        $request->session()->put(self::SESSION, ['job' => $data['job'], 'pen' => $data['pen_id'] ?? null, 'at' => now()->timestamp, 'done' => 0]);

        return redirect('/worker')->with('success', self::JOBS[$data['job']][0].': now scan the first goat.');
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
        abort_unless($request->user()->canModule('weights'), 403);
        $animal = $this->goat($request);
        $data = $request->validate(['weight_kg' => ['required', 'numeric', 'min:0.5', 'max:200'], 'body_score' => ['nullable', 'integer', 'between:1,5']], [], ['weight_kg' => 'weight']);
        WeightRecord::create(['animal_id' => $animal->id, 'weight_kg' => $data['weight_kg'], 'measured_on' => now()->toDateString(), 'measured_by' => $request->user()->name, 'body_score' => $data['body_score'] ?? null]);
        Farm::event($animal, 'weight', "Weighed {$data['weight_kg']} KG".(! empty($data['body_score']) ? ", body score {$data['body_score']}" : '').' (RFID gun)', $request->user()->name);
        Farm::log($request->user(), "Weighed {$animal->farm_number} at {$data['weight_kg']} KG", "/goats/{$animal->id}");
        self::tick($request);

        return redirect('/worker')->with('success', "{$animal->farm_number}: {$data['weight_kg']} KG saved. Scan the next goat.");
    }

    /** Health check on a round: looks fine (noted on its lifecycle), or a concern for the vet (the same as Report a Concern). */
    public function health(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canModule('health'), 403);
        $animal = $this->goat($request);
        $data = $request->validate(['fine' => ['required', 'boolean'], 'title' => ['nullable', 'required_if:fine,false', 'string', 'max:120'], 'severity' => ['nullable', 'required_if:fine,false', Rule::in(['low', 'medium', 'high'])], 'notes' => ['nullable', 'string', 'max:500']], [], ['title' => 'what you noticed']);
        if ($data['fine']) {
            Farm::event($animal, 'health', 'Checked on the health round: looks fine', $request->user()->name);
            self::tick($request);

            return redirect('/worker')->with('success', "{$animal->farm_number} looks fine. Scan the next goat.");
        }
        DB::transaction(function () use ($animal, $data, $request) {
            HealthRecord::create(['animal_id' => $animal->id, 'title' => $data['title'], 'severity' => $data['severity'], 'notes' => $data['notes'] ?? null, 'kind' => 'observation', 'occurred_on' => now(), 'recorded_by' => $request->user()->name, 'recorded_role' => $request->user()->roleLabel(), 'status' => 'open']);
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
        abort_unless($request->user()->canModule('sheds'), 403);
        $animal = Animal::with('pen.shed')->findOrFail($this->goat($request)->id);
        $job = self::job($request);
        $pen = Pen::with('shed')->findOrFail($request->validate(['pen_id' => ['nullable', 'integer', 'exists:pens,id']])['pen_id'] ?? $job['pen'] ?? 0);
        $moved = \App\Support\Movements::transfer($animal, $pen, $request->user());
        if ($moved) {
            Farm::log($request->user(), "Moved {$animal->farm_number} to {$pen->fullName()}", "/goats/{$animal->id}");
            self::tick($request);
        }

        return redirect('/worker')->with('success', $moved ? "{$animal->farm_number} moved to {$pen->fullName()}. Scan the next goat." : "{$animal->farm_number} is already in {$pen->fullName()}.");
    }
}
