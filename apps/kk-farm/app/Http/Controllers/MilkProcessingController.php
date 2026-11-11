<?php

namespace App\Http\Controllers;

use App\Models\MilkDispatch;
use App\Models\MilkProcessingBatch;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-211: App 17 Storage & Processing (optional). Milk sent to processing from a released lot becomes product batches:
 * product, litres used, heat treatment, output and yield, best-before. A batch is released for sale by someone other
 * than its maker; a heated product needs its heat treatment recorded. Each batch traces back to its milk lot.
 */
class MilkProcessingController extends Controller
{
    private function supervisor(Request $request): bool
    {
        return in_array($request->user()->role, ['ceo', 'manager', 'vet'], true);
    }

    private static function used(int $dispatchId): float
    {
        return (float) MilkProcessingBatch::where('dispatch_id', $dispatchId)->sum('input_l');
    }

    public function index(Request $request): Response
    {
        $sent = MilkDispatch::with('lot')->where('kind', 'processing')->orderByDesc('dispatched_on')->limit(60)->get();
        $batches = MilkProcessingBatch::with('dispatch.lot')->orderByDesc('made_on')->orderByDesc('id')->limit(200)->get();

        return Inertia::render('MilkProcessing', [
            'milk' => $sent->map(fn (MilkDispatch $d) => ['id' => $d->id, 'lot' => $d->lot?->code, 'on' => $d->dispatched_on->format('j M Y'), 'litres' => (float) $d->litres, 'left' => round((float) $d->litres - self::used($d->id), 2)])->values(),
            'batches' => $batches->map(fn (MilkProcessingBatch $b) => ['id' => $b->id, 'code' => $b->code, 'product' => MilkProcessingBatch::PRODUCTS[$b->product][0] ?? $b->product, 'lot' => $b->dispatch?->lot?->code,
                'input' => $b->input_l, 'output' => $b->output_qty, 'unit' => $b->output_unit, 'yield' => $b->input_l > 0 ? round($b->output_qty / $b->input_l * 100, 1) : null,
                'heat' => $b->heat_temp_c !== null ? "{$b->heat_temp_c} °C".($b->heat_minutes ? " for {$b->heat_minutes} min" : '') : null, 'made' => $b->made_on->format('j M Y'),
                'bestBefore' => $b->best_before?->format('j M Y'), 'status' => $b->status, 'by' => $b->made_by, 'releasedBy' => $b->released_by, 'notes' => $b->notes])->values(),
            'kpi' => ['litres' => round($batches->where('made_on', '>=', now()->subDays(30))->sum('input_l'), 1), 'batches' => $batches->where('made_on', '>=', now()->subDays(30))->count(),
                'toRelease' => $batches->where('status', 'made')->count(), 'waiting' => round($sent->sum(fn ($d) => max(0, (float) $d->litres - self::used($d->id))), 1)],
            'products' => collect(MilkProcessingBatch::PRODUCTS)->map(fn ($p, $k) => ['id' => $k, 'label' => $p[0], 'unit' => $p[1], 'heated' => in_array($k, MilkProcessingBatch::HEATED, true)])->values(),
            'canRelease' => $this->supervisor($request), 'me' => $request->user()->name,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->supervisor($request) || $request->user()->role === 'caretaker', 403);
        $data = $request->validate([
            'dispatch_id' => ['required', Rule::exists('milk_dispatches', 'id')->where('kind', 'processing')], 'product' => ['required', Rule::in(array_keys(MilkProcessingBatch::PRODUCTS))],
            'input_l' => ['required', 'numeric', 'gt:0', 'max:100000'], 'heat_temp_c' => ['nullable', 'numeric', 'between:20,150'], 'heat_minutes' => ['nullable', 'integer', 'between:0,600'],
            'output_qty' => ['required', 'numeric', 'gt:0', 'max:100000'], 'made_on' => ['required', 'date', 'before_or_equal:today'], 'best_before' => ['nullable', 'date', 'after_or_equal:made_on'],
            'notes' => ['nullable', 'string', 'max:300'],
        ], [], ['input_l' => 'litres used', 'output_qty' => 'output', 'dispatch_id' => 'milk']);
        $b = DB::transaction(function () use ($data, $request) {
            $d = MilkDispatch::lockForUpdate()->findOrFail($data['dispatch_id']);
            $left = round((float) $d->litres - self::used($d->id), 2);
            if ($data['input_l'] > $left + 0.001) {
                throw ValidationException::withMessages(['input_l' => "Only {$left} L of this milk is left to process."]);
            }
            $prefix = 'MPB-'.now()->format('ymd').'-';
            $n = MilkProcessingBatch::where('code', 'like', $prefix.'%')->count() + 1;

            return MilkProcessingBatch::create($data + ['code' => $prefix.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'output_unit' => MilkProcessingBatch::PRODUCTS[$data['product']][1], 'made_by' => $request->user()->name]);
        });
        Farm::log($request->user(), "Processing batch {$b->code}: {$b->input_l} L into {$b->output_qty} {$b->output_unit} ".MilkProcessingBatch::PRODUCTS[$b->product][0], '/dairy/processing');

        return back()->with('success', "{$b->code} recorded. Someone else releases it for sale.");
    }

    public function decide(Request $request, MilkProcessingBatch $batch): RedirectResponse
    {
        abort_unless($this->supervisor($request), 403, 'The owner, manager or vet releases processed products.');
        abort_unless($batch->status === 'made', 422, 'This batch was already decided.');
        $data = $request->validate(['decision' => ['required', Rule::in(['released', 'discarded'])], 'note' => ['nullable', 'required_if:decision,discarded', 'string', 'max:300']], [], ['note' => 'reason']);
        $user = $request->user();
        if ($data['decision'] === 'released') {
            if ($batch->made_by === $user->name && $user->role !== 'ceo') {
                throw ValidationException::withMessages(['decision' => 'Someone other than the maker releases the batch.']);
            }
            if (in_array($batch->product, MilkProcessingBatch::HEATED, true) && $batch->heat_temp_c === null) {
                throw ValidationException::withMessages(['decision' => 'Record the heat treatment before releasing.']);
            }
        }
        $batch->update(['status' => $data['decision'], 'released_by' => $user->name, 'released_at' => now(), 'notes' => trim(($batch->notes ? $batch->notes.' · ' : '').($data['note'] ?? '')) ?: null]);

        return back()->with('success', $data['decision'] === 'released' ? "{$batch->code} released for sale." : "{$batch->code} discarded.");
    }
}
