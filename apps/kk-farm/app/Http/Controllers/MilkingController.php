<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Lactation;
use App\Models\MilkRecord;
use App\Models\Organization;
use App\Support\Farm;
use App\Support\Feed;
use App\Support\Milking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** D-117: Milking: does in milk, milk per session, discarded milk under withdrawal, drying off. */
class MilkingController extends Controller
{
    public function index(Request $request): Response
    {
        $org = Feed::org();
        $today = Feed::now($org)->startOfDay();
        $open = Lactation::open()->with('animal.pen.shed', 'animal.breed')->get()->filter(fn ($l) => $l->animal?->status === 'active')->sortBy('animal.farm_number')->values();
        $week = Milking::weekAverages();
        $weights = Feed::latestWeights();
        $records = MilkRecord::current()->where('milked_on', '>=', $today->copy()->subDays(29)->toDateString())->get();
        $totals = MilkRecord::current()->whereIn('lactation_id', $open->pluck('id'))->selectRaw('lactation_id, sum(litres) as l, max(milked_on) as last')->groupBy('lactation_id')->get()->keyBy('lactation_id');
        $todayRows = $records->filter(fn ($r) => $r->milked_on->isSameDay($today));
        $monthStart = $today->copy()->startOfMonth();
        $month = MilkRecord::current()->where('milked_on', '>=', $monthStart->toDateString())->get();
        $days = collect(range(29, 0))->map(fn ($d) => $today->copy()->subDays($d));
        $byDay = $records->groupBy(fn ($r) => $r->milked_on->toDateString());

        return Inertia::render('Milking', [
            'today' => $today->toDateString(),
            'sessions' => MilkRecord::SESSIONS,
            'reasons' => Lactation::DRY_REASONS,
            'stats' => [
                'inMilk' => $open->count(), 'today' => round($todayRows->where('discarded', false)->sum('litres'), 1), 'todayDiscarded' => round($todayRows->where('discarded', true)->sum('litres'), 1),
                'weekAvg' => round($records->filter(fn ($r) => $r->milked_on->gte($today->copy()->subDays(6)))->where('discarded', false)->sum('litres') / 7, 1),
                'month' => round($month->where('discarded', false)->sum('litres'), 1), 'monthDiscarded' => round($month->where('discarded', true)->sum('litres'), 1), 'monthName' => $today->format('F'),
                'high' => $open->filter(fn ($l) => Milking::isHighYield($l->animal))->count(),
            ],
            'chart' => $days->map(fn ($d) => ['day' => $d->format('j M'), 'kept' => round(($byDay[$d->toDateString()] ?? collect())->where('discarded', false)->sum('litres'), 1),
                'discarded' => round(($byDay[$d->toDateString()] ?? collect())->where('discarded', true)->sum('litres'), 1)])->values(),
            'does' => $open->map(function (Lactation $l) use ($week, $weights, $totals, $today, $org, $todayRows) {
                $doe = $l->animal;
                $withdrawal = Milking::withdrawalOn($doe->id, $today);
                $std = Feed::standardFor($doe, $weights[$doe->id] ?? null);

                return [
                    'lactationId' => $l->id, 'id' => $doe->id, 'number' => $doe->farm_number, 'name' => $doe->name, 'breed' => $doe->breed?->name, 'pen' => $doe->pen?->fullName(),
                    'lactation' => $l->number, 'started' => $l->started_on->format('j M Y'), 'startedIso' => $l->started_on->toDateString(), 'source' => $l->source,
                    'dim' => $l->daysInMilk(), 'avg7' => $week[$doe->id] ?? null, 'total' => round((float) ($totals[$l->id]->l ?? 0), 1),
                    'last' => isset($totals[$l->id]) ? Carbon::parse($totals[$l->id]->last)->format('j M') : null,
                    'todayBySession' => $todayRows->where('animal_id', $doe->id)->mapWithKeys(fn ($r) => [$r->session => $r->litres])->all(),
                    'epc' => \App\Models\RfidAssignment::where('animal_id', $doe->id)->whereNull('valid_to')->with('tag')->first()?->tag?->tag_code, // D-195: scan the doe in the milking round
                    'high' => Milking::isHighYield($doe), 'pregnant' => in_array($doe->repro_status, ['pregnant', 'expected_kidding'], true),
                    'withdrawal' => $withdrawal?->format('j M Y'), 'standard' => $std?->name, 'cp' => $std?->range(),
                    'dryDue' => $org->dry_off_days && $l->daysInMilk() >= $org->dry_off_days,
                ];
            })->values(),
            'dried' => Lactation::with('animal')->whereNotNull('dried_on')->orderByDesc('dried_on')->orderByDesc('id')->limit(10)->get()->map(fn (Lactation $l) => [
                'id' => $l->animal_id, 'number' => $l->animal?->farm_number, 'lactation' => $l->number, 'started' => $l->started_on->format('j M Y'), 'dried' => $l->dried_on->format('j M Y'),
                'days' => $l->daysInMilk(), 'reason' => $l->dry_reason, 'by' => $l->dried_by, 'total' => round((float) MilkRecord::current()->where('lactation_id', $l->id)->sum('litres'), 1),
            ]),
            'settings' => ['highYield' => $org->high_yield_litres !== null ? (float) $org->high_yield_litres : null, 'dryOffDays' => $org->dry_off_days],
            'can' => ['record' => $request->user()->passes('record_milk'), 'manage' => $request->user()->passes('manage_milking')],
        ]);
    }

    public function record(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('record_milk'), 403);
        $data = $request->validate([
            'milked_on' => ['required', 'date', 'before_or_equal:today'],
            'session' => ['required', Rule::in(array_keys(MilkRecord::SESSIONS))],
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.id' => ['required', 'uuid'],
            'rows.*.animal_id' => ['required', 'uuid', 'distinct', 'exists:animals,id'],
            'rows.*.litres' => ['required', 'numeric', 'min:0', 'max:20'],
        ], [], ['rows.*.litres' => 'litres']);
        $out = DB::transaction(fn () => Milking::record(Carbon::parse($data['milked_on']), $data['session'], $data['rows'], $request->user()->name));
        $session = MilkRecord::SESSIONS[$data['session']];
        Farm::log($request->user(), "Recorded {$session} milk for ".count($data['rows']).' does on '.$data['milked_on'], '/milking');

        return back()->with('success', "{$session} milk saved: {$out['saved']} does".($out['corrected'] ? ", {$out['corrected']} corrected" : '').($out['discarded'] ? ", {$out['discarded']} marked discard (withdrawal)" : '').'.');
    }

    public function dryOff(Request $request, Lactation $lactation): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_milking'), 403);
        abort_if($lactation->dried_on !== null, 422, 'Already dried off.');
        $data = $request->validate(['dried_on' => ['required', 'date', 'before_or_equal:today'], 'reason' => ['required', Rule::in(array_keys(Lactation::DRY_REASONS))], 'note' => ['nullable', 'string', 'max:100']]);
        $reason = Lactation::DRY_REASONS[$data['reason']].(! empty($data['note']) ? ": {$data['note']}" : '');
        DB::transaction(fn () => Milking::dryOff($lactation->load('animal'), Carbon::parse($data['dried_on']), $reason, $request->user()->name));

        return back()->with('success', "{$lactation->animal->farm_number} dried off.");
    }

    /** Start a lactation by hand, e.g. a doe whose kidding was not recorded. */
    public function start(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_milking'), 403);
        $data = $request->validate(['tag' => ['required', 'string', 'max:40'], 'started_on' => ['required', 'date', 'before_or_equal:today']]);
        $doe = Animal::active()->where(DB::raw('upper(farm_number)'), strtoupper(trim($data['tag'])))->first();
        if (! $doe || $doe->sex !== 'female') {
            throw \Illuminate\Validation\ValidationException::withMessages(['tag' => 'No active doe with this Tag ID.']);
        }
        if (Lactation::where('animal_id', $doe->id)->open()->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['tag' => "{$doe->farm_number} is already in milk."]);
        }
        Milking::start($doe, Carbon::parse($data['started_on']), 'arrival', $request->user()->name);

        return back()->with('success', "{$doe->farm_number} is in milk.");
    }

    public function settings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_milking'), 403);
        $data = $request->validate(['high_yield_litres' => ['nullable', 'numeric', 'between:0.2,10'], 'dry_off_days' => ['nullable', 'integer', 'between:30,500']]);
        Organization::whereKey($request->user()->org_id)->update(['high_yield_litres' => $data['high_yield_litres'] ?? null, 'dry_off_days' => $data['dry_off_days'] ?? null]);
        Farm::log($request->user(), 'Milking settings: high yield '.($data['high_yield_litres'] ?? '-').' L/day, dry off after '.($data['dry_off_days'] ?? '-').' days', '/milking');

        return back()->with('success', 'Milking settings saved.');
    }
}
