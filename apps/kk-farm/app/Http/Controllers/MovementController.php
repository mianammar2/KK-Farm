<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\AnimalMovement;
use App\Models\Pen;
use App\Models\Sale;
use App\Support\Farm;
use App\Support\Movements;
use App\Support\Withdrawal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** D-076: workbook "Animal Movements": pen transfers (one goat or a batch) and exits, with history. */
class MovementController extends Controller
{
    public function index(Request $request): Response
    {
        $withdrawal = Withdrawal::active();

        return Inertia::render('Movements', [
            'movements' => AnimalMovement::with(['animal', 'fromPen.shed', 'toPen.shed'])->orderByDesc('moved_on')->orderByDesc('id')->limit(200)->get()->map(fn (AnimalMovement $m) => [
                'id' => $m->id, 'on' => $m->moved_on->format('d/m/Y'), 'type' => $m->type, 'typeLabel' => AnimalMovement::TYPES[$m->type][0] ?? $m->type,
                'goatId' => $m->animal_id, 'goat' => $m->animal->farm_number, 'from' => $m->fromPen?->fullName(), 'to' => $m->toPen?->fullName() ?? $m->destination,
                'reason' => $m->reason, 'by' => $m->authorized_by ?? $m->recorded_by, 'amount' => $m->amount_minor !== null ? $m->amount_minor / 100 : null, 'evidence' => $m->evidence_ref,
            ]),
            'goats' => Animal::with('pen.shed')->active()->orderBy('farm_number')->get()->map(fn (Animal $a) => [
                'id' => $a->id, 'number' => $a->farm_number, 'name' => $a->name, 'pen' => $a->pen?->fullName(), 'penId' => $a->pen_id,
                'withdrawal' => isset($withdrawal[$a->id]['meat']) && $withdrawal[$a->id]['meat'] ? \Illuminate\Support\Carbon::parse($withdrawal[$a->id]['meat'])->format('d/m') : null,
            ]),
            'pens' => Pen::with('shed')->where('kind', 'pen')->whereHas('shed', fn ($q) => $q->where('status', 'active'))->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName()])->sortBy('name')->values(),
            'types' => collect(AnimalMovement::TYPES)->except(['death', 'culling'])->map(fn ($t) => ['label' => $t[0], 'exit' => $t[1]]), // D-260
            'month' => AnimalMovement::where('moved_on', '>=', now()->startOfMonth())->groupBy('type')->selectRaw('type, count(*) as n')->pluck('n', 'type'),
            'canExit' => $request->user()->passes('exit_animal'),
            'saleCategories' => Sale::CATEGORIES,
        ]);
    }

    /** One or many goats: a pen transfer (to_pen_id) or an exit of the same type, all in one go. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'animal_ids' => ['required', 'array', 'min:1', 'max:500'], 'animal_ids.*' => ['uuid', 'distinct', 'exists:animals,id'],
            'type' => ['required', Rule::in(array_diff(array_keys(AnimalMovement::TYPES), ['death', 'culling']))], // D-260 'moved_on' => ['required', 'date', 'before_or_equal:today'],
            'to_pen_id' => ['nullable', 'required_if:type,transfer', 'exists:pens,id'],
            'destination' => ['nullable', 'string', 'max:120'], 'reason' => ['nullable', 'string', 'max:200'], 'authorized_by' => ['nullable', 'string', 'max:80'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'], 'fee' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'evidence_ref' => ['nullable', 'string', 'max:80'], 'notes' => ['nullable', 'string', 'max:500'],
            'sale_category' => ['nullable', Rule::in(['live_goat', 'breeding_goat'])], 'capacity_override' => ['nullable', 'string', 'max:200'], // D-207
        ], ['to_pen_id.required_if' => 'Choose the pen to move to.'], ['animal_ids' => 'goats', 'amount' => 'price']);
        $exit = AnimalMovement::TYPES[$data['type']][1];
        abort_if($exit && ! $request->user()->passes('exit_animal'), 403, 'Only the owner or manager records a goat leaving the farm.');
        if ($data['type'] === 'sale' && empty($data['amount'])) {
            throw ValidationException::withMessages(['amount' => 'Enter the sale price per goat.']);
        }
        $goats = Animal::with('pen.shed')->whereIn('id', $data['animal_ids'])->get();
        $pen = $exit ? null : Pen::with('shed')->findOrFail($data['to_pen_id']);

        // All or nothing: a blocked goat (withdrawal, already gone) stops the whole batch with its reason.
        $done = DB::transaction(function () use ($goats, $data, $exit, $pen, $request) {
            $n = 0;
            foreach ($goats as $goat) {
                $n += $exit ? (Movements::exit($goat, $data['type'], $request->user(), $data) ? 1 : 0) : (Movements::transfer($goat, $pen, $request->user(), $data) ? 1 : 0);
            }

            return $n;
        });
        $label = AnimalMovement::TYPES[$data['type']][0];
        Farm::log($request->user(), "{$label}: {$done} goat".($done === 1 ? '' : 's').($pen ? " to {$pen->fullName()}" : ''), '/movements');

        return back()->with('success', $exit ? "{$label} recorded for {$done} goat".($done === 1 ? '' : 's').'.' : "{$done} goat".($done === 1 ? '' : 's')." moved to {$pen->fullName()}.".($done < $goats->count() ? ' The others were already there.' : ''));
    }
}
