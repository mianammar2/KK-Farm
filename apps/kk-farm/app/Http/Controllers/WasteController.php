<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\LedgerEntry;
use App\Models\Task;
use App\Models\WasteDisposal;
use App\Models\WasteStream;
use App\Support\Farm;
use App\Support\Waste;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** D-204: journey O Waste-to-Safe-Disposal (V3 flow 38), in Water, Climate & Environment. */
class WasteController extends Controller
{
    private function lead(Request $request): bool
    {
        return in_array($request->user()->role, ['ceo', 'manager', 'vet'], true);
    }

    public function index(Request $request): Response
    {
        $streams = WasteStream::orderBy('kind')->orderBy('name')->get();
        $disposed = WasteDisposal::whereNotNull('animal_id')->pluck('animal_id')->flip();

        return Inertia::render('Waste', [
            'streams' => $streams->map(fn (WasteStream $s) => ['id' => $s->id, 'kind' => $s->kind, 'name' => $s->name, 'method' => $s->method, 'instructions' => $s->instructions, 'adviser' => $s->adviser,
                'adviserRole' => $s->adviser_role, 'advised' => $s->advised_on?->format('j M Y'), 'advisedOn' => $s->advised_on?->toDateString(), 'contractor' => $s->contractor, 'licence' => $s->licence_no, 'every' => $s->pickup_every_days,
                'approved' => $s->approved(), 'active' => $s->active, 'biohazard' => in_array($s->kind, WasteStream::BIOHAZARD, true)] + Waste::pickups($s)),
            'disposals' => WasteDisposal::with(['stream', 'animal'])->orderByRaw('verified_at is not null')->orderByDesc('disposed_on')->orderByDesc('id')->limit(150)->get()->map(fn (WasteDisposal $d) => [
                'id' => $d->id, 'on' => $d->disposed_on->format('j M Y'), 'stream' => $d->stream?->name, 'kind' => $d->stream?->kind, 'qty' => $d->qty, 'unit' => $d->unit, 'method' => $d->method,
                'deviation' => $d->deviation_reason, 'goat' => $d->animal?->farm_number, 'evidence' => $d->evidence_ref, 'ppe' => $d->ppe_used, 'disinfected' => $d->area_disinfected, 'cost' => $d->cost_minor,
                'by' => $d->recorded_by, 'verifiedBy' => $d->verified_by, 'audit' => $d->audit_note, 'notes' => $d->notes]),
            'carcasses' => Animal::where('status', 'deceased')->where('exit_date', '>=', now()->subDays(90)->toDateString())->orderByDesc('exit_date')->get(['id', 'farm_number', 'exit_date'])
                ->reject(fn ($a) => isset($disposed[$a->id]))->map(fn ($a) => ['id' => $a->id, 'number' => $a->farm_number, 'died' => $a->exit_date?->format('j M Y')])->values(),
            'kpi' => Waste::kpis(),
            'options' => ['kinds' => WasteStream::KINDS, 'methods' => WasteStream::METHODS],
            'isLead' => $this->lead($request), 'me' => $request->user()->name,
        ]);
    }

    /** A waste route and its method as set by the qualified adviser (recorded by the owner, manager or vet). */
    public function stream(Request $request, ?WasteStream $stream = null): RedirectResponse
    {
        abort_unless($this->lead($request), 403, 'The owner, manager or vet records waste routes.');
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(WasteStream::KINDS))], 'name' => ['required', 'string', 'max:80'], 'method' => ['required', Rule::in(array_keys(WasteStream::METHODS))],
            'instructions' => ['nullable', 'string', 'max:1000'], 'adviser' => ['nullable', 'string', 'max:120'], 'adviser_role' => ['nullable', 'required_with:adviser', 'string', 'max:80'],
            'advised_on' => ['nullable', 'required_with:adviser', 'date', 'before_or_equal:today'], 'contractor' => ['nullable', 'required_if:method,contractor,sharps_box,rendering', 'string', 'max:120'],
            'licence_no' => ['nullable', 'string', 'max:60'], 'pickup_every_days' => ['nullable', 'integer', 'between:1,365'], 'active' => ['boolean']],
            [], ['adviser_role' => "adviser's role", 'advised_on' => 'date advised']);
        $stream ? $stream->update($data) : WasteStream::create($data + ['recorded_by' => $request->user()->name]);

        return back()->with('success', empty($data['adviser']) ? "{$data['name']} saved, but it cannot be used until a qualified adviser sets its method." : "{$data['name']} saved.");
    }

    /** Record a disposal. The route must have an adviser's method; biological waste needs protection and disinfection. */
    public function dispose(Request $request): RedirectResponse
    {
        $data = $request->validate(['stream_id' => ['required', 'exists:waste_streams,id'], 'disposed_on' => ['required', 'date', 'before_or_equal:today'], 'qty' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', Rule::in(['kg', 'litre', 'count', 'trolley', 'bag'])], 'method' => ['required', Rule::in(array_keys(WasteStream::METHODS))], 'deviation_reason' => ['nullable', 'string', 'max:300'],
            'animal_id' => ['nullable', 'exists:animals,id'], 'evidence_ref' => ['nullable', 'string', 'max:120'], 'ppe_used' => ['boolean'], 'area_disinfected' => ['boolean'],
            'cost' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:500']]);
        $stream = WasteStream::findOrFail($data['stream_id']);
        $errors = [];
        if (! $stream->approved()) {
            $errors['stream_id'] = "{$stream->name} has no method set by a qualified adviser yet.";
        }
        if ($data['method'] !== $stream->method && empty($data['deviation_reason'])) {
            $errors['deviation_reason'] = 'The adviser set '.WasteStream::METHODS[$stream->method].'. Give the reason for a different method.';
        }
        if (in_array($stream->kind, WasteStream::BIOHAZARD, true) && (empty($data['ppe_used']) || empty($data['area_disinfected']))) {
            $errors['ppe_used'] = 'Biological waste: confirm gloves / protection were used and the area was disinfected.';
        }
        if ($stream->kind === 'carcass') {
            $a = ! empty($data['animal_id']) ? Animal::find($data['animal_id']) : null;
            if (! $a || $a->status !== 'deceased') {
                $errors['animal_id'] = 'Choose the dead goat.';
            } elseif (WasteDisposal::where('animal_id', $a->id)->exists()) {
                $errors['animal_id'] = "{$a->farm_number}'s carcass is already disposed of.";
            }
        }
        if (in_array($stream->method, ['contractor', 'sharps_box', 'rendering'], true) && empty($data['evidence_ref'])) {
            $errors['evidence_ref'] = 'Enter the contractor manifest or receipt number.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $user = $request->user();
        DB::transaction(function () use ($data, $stream, $user) {
            $minor = ! empty($data['cost']) ? (int) round($data['cost'] * 100) : null;
            $ledger = $minor ? LedgerEntry::create(['direction' => 'out', 'category' => 'Waste Disposal', 'amount' => $minor / 100, 'occurred_on' => $data['disposed_on'], 'counterparty' => $stream->contractor,
                'description' => "{$stream->name}: ".WasteStream::METHODS[$data['method']], 'recorded_by' => $user->name, 'payment_status' => 'pending']) : null;
            $d = WasteDisposal::create(collect($data)->except('cost')->all() + ['cost_minor' => $minor, 'ledger_entry_id' => $ledger?->id, 'recorded_by' => $user->name,
                'ppe_used' => (bool) ($data['ppe_used'] ?? false), 'area_disinfected' => (bool) ($data['area_disinfected'] ?? false)]);
            if ($d->animal_id) {
                Task::where('animal_id', $d->animal_id)->where('title', 'like', 'Dispose carcass:%')->whereIn('status', Task::OPEN)->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $user->name]);
                Farm::event($d->animal, 'exit', 'Carcass disposed: '.WasteStream::METHODS[$d->method].($d->evidence_ref ? " ({$d->evidence_ref})" : ''), $user->name);
            }
        });

        return back()->with('success', 'Disposal recorded. Someone else audits it.');
    }

    /** Disposal audit by the owner, manager or vet, not the person who recorded it. */
    public function audit(Request $request, WasteDisposal $disposal): RedirectResponse
    {
        abort_unless($this->lead($request), 403);
        abort_if($disposal->verified_at !== null, 422, 'Already audited.');
        abort_if($disposal->recorded_by === $request->user()->name && $request->user()->role !== 'ceo', 403, 'Someone other than the recorder audits the disposal.');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $disposal->update(['verified_by' => $request->user()->name, 'verified_at' => now(), 'audit_note' => $data['note'] ?? null]);

        return back()->with('success', 'Disposal audited.');
    }
}
