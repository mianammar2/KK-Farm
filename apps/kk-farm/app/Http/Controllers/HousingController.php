<?php

namespace App\Http\Controllers;

use App\Models\AnimalMovement;
use App\Models\Pen;
use App\Models\PenCleaning;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-207: App 07 Housing gaps: pen status (only active pens take goats), occupancy against capacity with BR-004 (a
 * full pen refuses a move unless the owner or manager authorises it with a reason; care moves are never stopped),
 * and cleaning records: a deep clean or disinfection needs the pen empty, closes it while it runs, and is verified by
 * someone other than the cleaner before the pen opens again.
 */
class HousingController extends Controller
{
    private function supervisor(Request $request): bool
    {
        return in_array($request->user()->role, ['ceo', 'manager', 'vet'], true);
    }

    public function index(Request $request): Response
    {
        $pens = Pen::with('shed')->withCount(['animals' => fn ($q) => $q->active()])->where('kind', 'pen')->get()->sortBy(fn (Pen $p) => $p->fullName())->values();
        $last = PenCleaning::whereNotNull('finished_at')->selectRaw('pen_id, max(finished_at) as at')->groupBy('pen_id')->pluck('at', 'pen_id');

        return Inertia::render('Housing', [
            'pens' => $pens->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName(), 'purpose' => $p->purpose, 'capacity' => $p->capacity, 'count' => $p->animals_count,
                'pct' => $p->capacity ? (int) round($p->animals_count / $p->capacity * 100) : null, 'over' => $p->capacity && $p->animals_count > $p->capacity,
                'status' => $p->status, 'reason' => $p->status_reason, 'statusBy' => $p->status_by, 'lastClean' => isset($last[$p->id]) ? \Illuminate\Support\Carbon::parse($last[$p->id])->format('d/m/Y') : null]),
            'cleanings' => PenCleaning::with('pen.shed')->orderByRaw('finished_at is not null, verified_at is not null')->orderByDesc('started_at')->limit(100)->get()->map(fn (PenCleaning $c) => [
                'id' => $c->id, 'pen' => $c->pen?->fullName(), 'kind' => $c->kind, 'started' => $c->started_at->format('d/m g:i A'), 'finished' => $c->finished_at?->format('d/m g:i A'),
                'disinfectant' => $c->disinfectant, 'minutes' => $c->contact_minutes, 'notes' => $c->notes, 'by' => $c->done_by, 'verifiedBy' => $c->verified_by]),
            'overrides' => AnimalMovement::with(['animal', 'toPen.shed'])->whereNotNull('capacity_override')->where('moved_on', '>=', now()->subDays(90))->orderByDesc('id')->limit(30)->get()
                ->map(fn ($m) => ['on' => $m->moved_on->format('d/m/Y'), 'goat' => $m->animal?->farm_number, 'pen' => $m->toPen?->fullName(), 'why' => $m->capacity_override]),
            'kpi' => ['full' => $pens->filter(fn ($p) => $p->capacity && $p->animals_count >= $p->capacity)->count(), 'over' => $pens->filter(fn ($p) => $p->capacity && $p->animals_count > $p->capacity)->count(),
                'closed' => $pens->where('status', '!=', 'active')->count(), 'toVerify' => PenCleaning::whereNotNull('finished_at')->whereNull('verified_at')->count(),
                'occupancy' => $pens->sum('capacity') ? (int) round($pens->filter(fn ($p) => $p->capacity)->sum('animals_count') / $pens->sum('capacity') * 100) : null],
            'options' => ['kinds' => PenCleaning::KINDS, 'status' => ['active' => 'Active', 'maintenance' => 'Maintenance', 'closed' => 'Closed']],
            'canSupervise' => $this->supervisor($request), 'me' => $request->user()->name,
        ]);
    }

    public function status(Request $request, Pen $pen): RedirectResponse
    {
        abort_unless($this->supervisor($request), 403);
        abort_if($pen->status === 'cleaning', 422, 'Finish and verify the cleaning first.');
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'maintenance', 'closed'])], 'reason' => ['nullable', 'required_unless:status,active', 'string', 'max:300']]);
        if ($data['status'] === 'closed' && $pen->animals()->active()->exists()) {
            throw ValidationException::withMessages(['status' => 'Move the goats out before closing the pen.']);
        }
        $pen->update(['status' => $data['status'], 'status_reason' => $data['status'] === 'active' ? null : $data['reason'], 'status_by' => $request->user()->name, 'status_at' => now()]);

        return back()->with('success', "{$pen->fullName()}: {$data['status']}.");
    }

    /** Start a cleaning. Deep clean and disinfection need the pen empty and close it until verified. */
    public function start(Request $request, Pen $pen): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(PenCleaning::KINDS))], 'disinfectant' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:500']]);
        $empty = in_array($data['kind'], PenCleaning::EMPTY_PEN, true);
        if ($empty && $pen->animals()->active()->exists()) {
            throw ValidationException::withMessages(['kind' => "{$pen->fullName()} must be empty for a ".strtolower(PenCleaning::KINDS[$data['kind']]).'. Move the goats first.']);
        }
        abort_if(PenCleaning::where('pen_id', $pen->id)->whereNull('finished_at')->exists(), 422, 'A cleaning is already running here.');
        DB::transaction(function () use ($pen, $data, $empty, $request) {
            PenCleaning::create($data + ['pen_id' => $pen->id, 'started_at' => now(), 'done_by' => $request->user()->name] + ($empty ? [] : ['finished_at' => now()]));
            if ($empty) {
                $pen->update(['status' => 'cleaning', 'status_reason' => PenCleaning::KINDS[$data['kind']], 'status_by' => $request->user()->name, 'status_at' => now()]);
            }
        });
        Farm::log($request->user(), PenCleaning::KINDS[$data['kind']]." of {$pen->fullName()}", '/housing');

        return back()->with('success', $empty ? "{$pen->fullName()} closed for cleaning." : 'Routine clean recorded.');
    }

    public function finish(Request $request, PenCleaning $cleaning): RedirectResponse
    {
        abort_if($cleaning->finished_at !== null, 422, 'Already finished.');
        $data = $request->validate(['disinfectant' => ['nullable', 'required_if:kind,disinfection', 'string', 'max:120'], 'contact_minutes' => ['nullable', 'integer', 'between:1,1440'], 'notes' => ['nullable', 'string', 'max:500']]);
        if (in_array($cleaning->kind, ['disinfection', 'after_outbreak', 'after_loss'], true) && (empty($data['disinfectant'] ?? $cleaning->disinfectant) || empty($data['contact_minutes']))) {
            throw ValidationException::withMessages(['disinfectant' => 'Record the disinfectant and its contact time.']);
        }
        $cleaning->update(array_filter($data, fn ($v) => $v !== null) + ['finished_at' => now()]);

        return back()->with('success', 'Cleaning finished. Someone else verifies it before the pen opens.');
    }

    /** Verification by a supervisor other than the cleaner; the pen opens again. */
    public function verify(Request $request, PenCleaning $cleaning): RedirectResponse
    {
        abort_unless($this->supervisor($request), 403);
        abort_unless($cleaning->finished_at && ! $cleaning->verified_at, 422, 'Only a finished cleaning is verified.');
        abort_if($cleaning->done_by === $request->user()->name && $request->user()->role !== 'ceo', 403, 'Someone other than the cleaner verifies.');
        DB::transaction(function () use ($cleaning, $request) {
            $cleaning->update(['verified_by' => $request->user()->name, 'verified_at' => now()]);
            if ($cleaning->pen->status === 'cleaning' && ! PenCleaning::where('pen_id', $cleaning->pen_id)->whereNull('verified_at')->where('id', '!=', $cleaning->id)->whereIn('kind', PenCleaning::EMPTY_PEN)->exists()) {
                $cleaning->pen->update(['status' => 'active', 'status_reason' => null, 'status_by' => $request->user()->name, 'status_at' => now()]);
            }
        });

        return back()->with('success', 'Verified. The pen is open again.');
    }
}
