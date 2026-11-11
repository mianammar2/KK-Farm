<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Lactation;
use App\Models\MilkDispatch;
use App\Models\MilkLot;
use App\Models\MilkRecord;
use App\Models\MilkSession;
use App\Models\Task;
use App\Support\Farm;
use App\Support\Feed;
use App\Support\Movements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-188: App 17 Dairy & Milk, following V3 flow 17 after the per-doe litres are logged (Milking, D-117):
 * close the session: hygiene, equipment and operator check; udder check (mastitis → that doe's milk held, vet task);
 * milk under withdrawal is already held (BR-010); colostrum (first 3 days of a lactation) is never saleable (BR-009);
 * kid milk recorded; produced = held + colostrum + kid + discard + saleable (BR-014). Saleable milk becomes a lot
 * MLK-YYMMDD-NNN (pending QC) → milk quality and residue checks: fail → rejected (discard); pass → chilled / stored
 * (tank, temperature) → release by the quality lead, not the tester (BR-027) → dispatch or issue to processing only
 * from Released lots (BR-012), posting revenue once → hold / retest / recall with the buyers to notify (BR-013).
 */
class DairyController extends Controller
{
    public const COLOSTRUM_DAYS = 3;

    private function quality(Request $request): bool
    {
        return in_array($request->user()->role, ['ceo', 'manager', 'vet'], true);
    }

    /** Produced, held and colostrum litres of a day's session, from the current per-doe records. */
    private function tally(string $on, string $session): array
    {
        $records = MilkRecord::current()->with('lactation')->whereDate('milked_on', $on)->where('session', $session)->get();
        $colostrum = $records->filter(fn ($r) => ! $r->discarded && $r->lactation && $r->lactation->started_on->diffInDays(Carbon::parse($on)) < self::COLOSTRUM_DAYS);

        return [
            'records' => $records, 'produced' => round((float) $records->sum('litres'), 2), 'held' => round((float) $records->where('discarded', true)->sum('litres'), 2),
            'colostrum' => round((float) $colostrum->sum('litres'), 2), 'colostrumDoes' => $colostrum->pluck('animal_id')->all(),
        ];
    }

    public function index(Request $request): Response
    {
        $day = $request->query('day', now()->toDateString());
        $sessions = collect(['morning', 'evening'])->map(function ($s) use ($day) {
            $t = $this->tally($day, $s);
            $closed = MilkSession::with('lot')->whereDate('milked_on', $day)->where('session', $s)->first();

            return ['session' => $s, 'produced' => $t['produced'], 'held' => $t['held'], 'colostrum' => $t['colostrum'], 'does' => $t['records']->count(),
                'doeList' => $t['records']->map(fn ($r) => ['id' => $r->animal_id, 'number' => Animal::find($r->animal_id)?->farm_number, 'litres' => (float) $r->litres, 'held' => $r->discarded, 'reason' => $r->discard_reason])->values(),
                'closed' => $closed ? ['kid' => $closed->kid_l, 'discard' => $closed->discard_l, 'saleable' => $closed->saleable_l, 'by' => $closed->closed_by, 'lot' => $closed->lot?->code] : null];
        });
        $since = now()->subDays(30)->toDateString();
        $s30 = MilkSession::where('milked_on', '>=', $since)->get();
        $produced = (float) $s30->sum('produced_l');
        $revenue = (int) MilkDispatch::where('dispatched_on', '>=', $since)->whereNotNull('sale_id')->get()->sum(fn ($d) => $d->litres * $d->price_per_litre_minor);
        // Feed cost of the does in milk, from today's rations × 30 days: an estimate, labelled as such (BR-032).
        $does = Animal::whereIn('id', Lactation::open()->pluck('animal_id'))->get();
        $req = $does->isNotEmpty() ? Feed::perGoat($does, now()->toDateString()) : collect();
        $prices = \App\Models\FeedItem::pluck('cost_per_kg_minor', 'id');
        $feedDay = (int) $req->sum(fn ($r) => collect($r['lines'] ?? [])->sum(fn ($kg, $id) => $kg * ($prices[$id] ?? 0)));
        $milkDays = max(1, $s30->pluck('milked_on')->map->toDateString()->unique()->count());

        return Inertia::render('Dairy', [
            'day' => $day, 'sessions' => $sessions,
            'lots' => MilkLot::with(['session', 'dispatches.customer'])->where(fn ($q) => $q->whereNotIn('status', ['dispatched', 'rejected', 'recalled'])->orWhere('updated_at', '>=', now()->subDays(30)))
                ->orderByDesc('id')->limit(100)->get()->map(fn (MilkLot $l) => [
                    'id' => $l->id, 'code' => $l->code, 'litres' => $l->litres, 'remaining' => $l->remaining(), 'status' => $l->status, 'session' => $l->session?->milked_on->format('j M').' '.ucfirst($l->session?->session ?? ''),
                    'tank' => $l->tank, 'temp' => $l->storage_temp_c, 'residue' => $l->residue, 'testedBy' => $l->tested_by, 'releasedBy' => $l->released_by, 'hold' => $l->hold_reason,
                    'buyers' => $l->dispatches->map(fn ($d) => ['on' => $d->dispatched_on->format('j M'), 'to' => $d->customer?->name ?? $d->buyer ?? 'Processing', 'litres' => $d->litres, 'kind' => $d->kind])->values(),
                ]),
            'kpi' => [
                'perDoeDay' => $does->count() ? round($produced / $milkDays / $does->count(), 2) : null,
                'saleablePct' => $produced > 0 ? round($s30->sum('saleable_l') / $produced * 100) : null,
                'lossPct' => $produced > 0 ? round(($s30->sum('held_l') + $s30->sum('discard_l')) / $produced * 100, 1) : null,
                'produced' => round($produced, 1), 'revenue' => (int) round($revenue / 100),
                'costPerLitre' => $produced > 0 ? (int) round($feedDay * $milkDays / 100 / $produced) : null, 'feedDay' => (int) round($feedDay / 100),
            ],
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'options' => ['status' => MilkLot::STATUS],
            'canQuality' => $this->quality($request),
        ]);
    }

    public function close(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'milked_on' => ['required', 'date', 'before_or_equal:today'], 'session' => ['required', Rule::in(['morning', 'evening'])],
            'hygiene_ok' => ['required', 'boolean'], 'equipment_ok' => ['required', 'boolean'], 'operator' => ['required', 'string', 'max:80'],
            'mastitis' => ['array'], 'mastitis.*' => ['uuid', 'exists:animals,id'], 'kid_l' => ['nullable', 'numeric', 'min:0'], 'discard_l' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        abort_if(MilkSession::whereDate('milked_on', $data['milked_on'])->where('session', $data['session'])->exists(), 422, 'This session is already closed.');
        $user = $request->user();
        $lot = DB::transaction(function () use ($data, $user) {
            // Udder check: suspected mastitis → that doe's milk held for veterinary review.
            foreach ($data['mastitis'] ?? [] as $doeId) {
                MilkRecord::current()->where('animal_id', $doeId)->whereDate('milked_on', $data['milked_on'])->where('session', $data['session'])
                    ->update(['discarded' => true, 'discard_reason' => 'Mastitis suspected: veterinary review']);
                $doe = Animal::find($doeId);
                Task::create(['title' => mb_substr("Mastitis check: {$doe->farm_number}", 0, 190), 'type' => 'health', 'priority' => 'high', 'status' => 'open', 'due_on' => now()->toDateString(),
                    'animal_id' => $doeId, 'pen_id' => $doe->pen_id, 'department_id' => Department::where('name', 'Veterinary')->value('id'), 'notes' => 'Udder check at milking: milk held until the vet decides.']);
                Farm::event($doe, 'health', 'Udder check: mastitis suspected, milk held', $user->name);
            }
            $t = $this->tally($data['milked_on'], $data['session']);
            if ($t['produced'] <= 0) {
                throw ValidationException::withMessages(['session' => 'No litres logged for this session yet.']);
            }
            $kid = round((float) ($data['kid_l'] ?? 0), 2);
            $discard = round((float) ($data['discard_l'] ?? 0), 2);
            $hygieneFail = ! $data['hygiene_ok'] || ! $data['equipment_ok'];
            $available = round($t['produced'] - $t['held'] - $t['colostrum'], 2);
            if ($kid + $discard > $available + 0.001) { // BR-014 mass balance
                throw ValidationException::withMessages(['kid_l' => "Kid milk and discard ({$kid} + {$discard} L) are more than the milk left after held and colostrum ({$available} L)."]);
            }
            // A failed hygiene or equipment check discards what would have been saleable.
            if ($hygieneFail) {
                $discard = round($available - $kid, 2);
            }
            $saleable = round($available - $kid - $discard, 2);
            $session = MilkSession::create([
                'milked_on' => $data['milked_on'], 'session' => $data['session'], 'hygiene_ok' => $data['hygiene_ok'], 'equipment_ok' => $data['equipment_ok'], 'operator' => $data['operator'],
                'produced_l' => $t['produced'], 'held_l' => $t['held'], 'colostrum_l' => $t['colostrum'], 'kid_l' => $kid, 'discard_l' => $discard, 'saleable_l' => $saleable,
                'notes' => $data['notes'] ?? null, 'closed_by' => $user->name,
            ]);
            MilkRecord::whereIn('id', $t['records']->pluck('id'))->update(['session_id' => $session->id]);
            if ($saleable <= 0) {
                return null;
            }
            DB::statement('LOCK TABLE milk_lots IN SHARE ROW EXCLUSIVE MODE');
            $prefix = 'MLK-'.Carbon::parse($data['milked_on'])->format('ymd').'-';
            $n = MilkLot::where('code', 'like', "{$prefix}%")->count() + 1;

            return MilkLot::create(['code' => $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'session_id' => $session->id, 'litres' => $saleable, 'status' => 'pending_qc']);
        });

        return back()->with('success', $lot ? "Session closed: lot {$lot->code} ({$lot->litres} L) waits for QA." : 'Session closed: no saleable milk.');
    }

    /** Milk quality and residue checks: fail → rejected (discard); pass → chilled / stored. */
    public function test(Request $request, MilkLot $lot): RedirectResponse
    {
        abort_unless($this->quality($request) || $request->user()->role === 'caretaker', 403);
        abort_unless(in_array($lot->status, ['pending_qc', 'hold'], true), 422, 'Only a lot waiting for QA or on hold is tested.');
        $data = $request->validate([
            'appearance_ok' => ['required', 'boolean'], 'smell_ok' => ['required', 'boolean'], 'residue' => ['required', Rule::in(['negative', 'positive', 'not_tested'])],
            'tank' => ['nullable', 'string', 'max:40'], 'storage_temp_c' => ['nullable', 'numeric', 'between:-5,40'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $pass = $data['appearance_ok'] && $data['smell_ok'] && $data['residue'] === 'negative';
        $lot->update($data + ['status' => $pass ? 'stored' : 'rejected', 'tested_by' => $request->user()->name, 'tested_at' => now(), 'hold_reason' => $pass ? null : 'Failed quality or residue check: discard']);

        return back()->with('success', $pass ? "{$lot->code} passed and is stored. Release it for dispatch." : "{$lot->code} failed: rejected and to be discarded.");
    }

    /** Release by the quality lead, not the person who tested it (BR-027), except the owner. */
    public function release(Request $request, MilkLot $lot): RedirectResponse
    {
        abort_unless($this->quality($request), 403, 'The quality lead releases milk lots.');
        abort_unless($lot->status === 'stored', 422, 'Only a tested, stored lot is released.');
        abort_if(\App\Support\Quality::milkLotHold($lot->id) !== null, 422, "{$lot->code} is on a quality hold. Quality releases it first."); // D-189
        abort_if($lot->tested_by === $request->user()->name && $request->user()->role !== 'ceo', 403, 'Someone other than the tester releases the lot.');
        $lot->update(['status' => 'released', 'released_by' => $request->user()->name, 'released_at' => now()]);

        return back()->with('success', "{$lot->code} released for dispatch.");
    }

    public function hold(Request $request, MilkLot $lot): RedirectResponse
    {
        abort_unless($this->quality($request), 403);
        abort_unless(in_array($lot->status, ['stored', 'released'], true), 422);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $lot->update(['status' => 'hold', 'hold_reason' => $data['reason']]);

        return back()->with('success', "{$lot->code} on hold. Retest it before release.");
    }

    /** Recall: the lot is blocked and its buyers are listed for notification (forward trace, BR-013). */
    public function recall(Request $request, MilkLot $lot): RedirectResponse
    {
        abort_unless($this->quality($request), 403);
        abort_unless(in_array($lot->status, ['released', 'dispatched', 'hold'], true), 422);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $buyers = $lot->dispatches()->with('customer')->get()->map(fn ($d) => $d->customer?->name ?? $d->buyer ?? 'Processing')->unique()->implode(', ');
        DB::transaction(function () use ($lot, $data, $buyers, $request) {
            $lot->update(['status' => 'recalled', 'hold_reason' => $data['reason']]);
            Task::create(['title' => mb_substr("Milk recall {$lot->code}: notify buyers", 0, 190), 'type' => 'other', 'priority' => 'critical', 'status' => 'open', 'due_on' => now()->toDateString(),
                'department_id' => Department::where('name', 'Management')->value('id'), 'notes' => mb_substr("Reason: {$data['reason']}. Buyers: ".($buyers ?: 'none yet'), 0, 500)]);
            Farm::log($request->user(), "Recalled milk lot {$lot->code}", '/dairy?tab=lots');
        });

        return back()->with('success', "{$lot->code} recalled.".($buyers ? " Notify: {$buyers}." : ''));
    }

    /** Dispatch or issue to processing, from a Released lot only (BR-012); a sale posts revenue once. */
    public function dispatch(Request $request, MilkLot $lot): RedirectResponse
    {
        abort_unless($this->quality($request) || $request->user()->role === 'finance', 403);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['sale', 'processing'])], 'litres' => ['required', 'numeric', 'gt:0'], 'dispatched_on' => ['required', 'date', 'before_or_equal:today'],
            'customer_id' => ['nullable', 'exists:customers,id'], 'buyer' => ['nullable', 'string', 'max:120'], 'price_per_litre' => ['nullable', 'required_if:kind,sale', 'numeric', 'gt:0'],
        ], ['price_per_litre.required_if' => 'A sale needs the price per litre.']);
        if ($lot->status !== 'released') {
            throw ValidationException::withMessages(['litres' => "{$lot->code} is ".MilkLot::STATUS[$lot->status].': only Released milk can be dispatched.']);
        }
        if ($data['litres'] > $lot->remaining() + 0.001) {
            throw ValidationException::withMessages(['litres' => "Only {$lot->remaining()} L left in {$lot->code}."]);
        }
        DB::transaction(function () use ($lot, $data, $request) {
            $customer = ! empty($data['customer_id']) ? Customer::find($data['customer_id']) : null;
            $price = $data['kind'] === 'sale' ? (int) round($data['price_per_litre'] * 100) : null;
            $sale = $price ? Movements::sale([
                'sold_on' => $data['dispatched_on'], 'category' => 'product', 'item' => "Goat milk {$data['litres']} L · lot {$lot->code}", 'buyer' => $customer?->name ?? ($data['buyer'] ?? null),
                'gross' => $data['litres'] * $price / 100, 'customer_id' => $customer?->id, 'receipt_ref' => $lot->code,
            ], $request->user(), "Goat milk {$data['litres']} L · lot {$lot->code}") : null;
            MilkDispatch::create(['lot_id' => $lot->id, 'dispatched_on' => $data['dispatched_on'], 'kind' => $data['kind'], 'customer_id' => $customer?->id, 'buyer' => $data['buyer'] ?? null,
                'litres' => $data['litres'], 'price_per_litre_minor' => $price, 'sale_id' => $sale?->id, 'recorded_by' => $request->user()->name]);
            if ($lot->remaining() <= 0.001) {
                $lot->update(['status' => 'dispatched']);
            }
        });

        return back()->with('success', "{$data['litres']} L of {$lot->code} dispatched.");
    }
}
