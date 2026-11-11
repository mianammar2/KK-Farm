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
        // D-195: the feed actually given to the does in milk (Feed True Cost allocation), when meals were recorded.
        $actualFeed = $this->milkFeedCost(Carbon::parse($since), Carbon::parse(now()->toDateString()));
        $sold = MilkDispatch::where('dispatched_on', '>=', $since)->whereNotNull('sale_id')->sum('litres');
        $feedTotal = $actualFeed ?? ($feedDay * $milkDays / 100);

        $traceLot = $request->query('trace') ? MilkLot::find($request->query('trace')) : null; // D-196
        return Inertia::render('Dairy', [
            'trace' => $traceLot ? \App\Support\MilkTrace::trace($traceLot) : null,
            'recallKpi' => \App\Support\MilkTrace::kpis(),
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
                'costPerLitre' => $produced > 0 ? (int) round($feedTotal / $produced) : null, 'feedDay' => (int) round($feedDay / 100), 'costActual' => $actualFeed !== null,
                'revenuePerLitre' => $sold > 0 ? round($revenue / 100 / $sold, 1) : null,
                'contributionPerLitre' => $sold > 0 && $produced > 0 ? round($revenue / 100 / $sold - $feedTotal / $produced, 1) : null,
                'owed' => (int) round(MilkDispatch::whereNotNull('invoice_no')->whereNull('collected_at')->get()->sum(fn ($d) => $d->litres * $d->price_per_litre_minor) / 100),
            ],
            // D-195: customer milk orders, invoices and the monthly milk profit view (CEO).
            'orders' => \App\Models\MilkOrder::with('customer')->where(fn ($q) => $q->where('status', 'open')->orWhere('updated_at', '>=', now()->subDays(30)))->orderByRaw("status = 'open' desc")->orderBy('wanted_on')->get()
                ->map(fn ($o) => ['id' => $o->id, 'no' => $o->order_no, 'customer' => $o->customer?->name ?? $o->buyer, 'litres' => $o->litres, 'remaining' => $o->remaining(), 'price' => $o->price_per_litre_minor / 100,
                    'wanted' => $o->wanted_on->format('j M Y'), 'terms' => $o->terms, 'creditDays' => $o->credit_days, 'status' => $o->status, 'by' => $o->created_by]),
            'invoices' => MilkDispatch::with(['customer', 'lot', 'order'])->whereNotNull('invoice_no')->where(fn ($q) => $q->whereNull('collected_at')->orWhere('dispatched_on', '>=', now()->subDays(60)->toDateString()))
                ->orderByRaw('collected_at is null desc')->orderByDesc('id')->limit(100)->get()->map(fn ($d) => [
                    'id' => $d->id, 'invoice' => $d->invoice_no, 'on' => $d->dispatched_on->format('j M Y'), 'to' => $d->customer?->name ?? $d->buyer, 'litres' => $d->litres, 'amount' => round($d->litres * $d->price_per_litre_minor / 100),
                    'lot' => $d->lot?->code, 'order' => $d->order?->order_no, 'temp' => $d->dispatch_temp_c, 'due' => $d->due_on?->format('j M Y'), 'overdue' => $d->due_on && ! $d->collected_at && $d->due_on->lt(today()),
                    'collected' => $d->collected_at?->format('j M Y')]),
            'monthly' => $this->monthly(),
            'maxTemp' => (float) (\App\Support\Tenant::org()?->milk_dispatch_max_c ?? 7),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'options' => ['status' => MilkLot::STATUS],
            'canQuality' => $this->quality($request),
        ]);
    }

    /** D-195: feed cost (PKR) of the does in milk between two dates from recorded meals, or null when none were recorded. */
    private function milkFeedCost(Carbon $from, Carbon $to): ?float
    {
        if (! \App\Models\FeedUsage::current()->whereBetween('fed_on', [$from->toDateString(), $to->toDateString()])->exists()) {
            return null;
        }
        $inMilk = Lactation::where('started_on', '<=', $to->toDateString())->where(fn ($q) => $q->whereNull('dried_on')->orWhere('dried_on', '>=', $from->toDateString()))->pluck('animal_id')->unique()->all();

        return (float) \App\Support\FeedCost::build($from, $to)['goats']->whereIn('id', $inMilk)->sum('cost');
    }

    /** D-195: the last three months of milk: produced, lost, sold, revenue, feed cost, contribution. */
    private function monthly(): array
    {
        return collect(range(2, 0))->map(function ($m) {
            $from = now()->startOfMonth()->subMonths($m);
            $to = $m === 0 ? now()->startOfDay() : $from->copy()->endOfMonth()->startOfDay();
            $s = MilkSession::whereBetween('milked_on', [$from->toDateString(), $to->toDateString()])->get();
            $d = MilkDispatch::whereBetween('dispatched_on', [$from->toDateString(), $to->toDateString()])->whereNotNull('sale_id')->get();
            $produced = (float) $s->sum('produced_l');
            $revenue = $d->sum(fn ($x) => $x->litres * $x->price_per_litre_minor) / 100;
            $feed = $produced > 0 ? $this->milkFeedCost(Carbon::parse($from), Carbon::parse($to)) : null;

            return ['month' => $from->format('M Y'), 'produced' => round($produced, 1), 'saleable' => round((float) $s->sum('saleable_l'), 1), 'lost' => round((float) $s->sum('held_l') + (float) $s->sum('discard_l'), 1),
                'kid' => round((float) $s->sum('kid_l'), 1), 'colostrum' => round((float) $s->sum('colostrum_l'), 1), 'sold' => round((float) $d->sum('litres'), 1), 'revenue' => round($revenue),
                'feed' => $feed !== null ? round($feed) : null, 'contribution' => $feed !== null ? round($revenue - $feed) : null,
                'revenuePerLitre' => $d->sum('litres') > 0 ? round($revenue / $d->sum('litres'), 1) : null];
        })->all();
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
                // D-196 (journey G): her milk in earlier lots of the last few days is held, or listed for recall.
                \App\Support\MilkTrace::doeFlagged($doe, 'mastitis suspected at the udder check', now()->subDays(\App\Support\MilkTrace::traceDays())->toDateString(), $user);
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
        if ($data['residue'] === 'positive') {
            \App\Support\MilkTrace::residuePositive($lot, $request->user()); // D-196: find the treated doe
        }

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
        $buyers = $lot->dispatches()->with('customer')->get()->map(fn ($d) => $d->kind === 'processing' ? 'Processing' : ($d->customer?->name ?? $d->buyer ?? 'buyer'))->unique()->implode(', ');
        // D-196: every recall is a quality case (containment recall), so the corrective action is tracked and verified.
        $case = \App\Support\QualityCases::open(['source' => 'milk', 'subject' => 'milk_lot', 'milk_lot_id' => $lot->id, 'severity' => 'critical', 'immediate_risk' => true, 'containment' => 'recall',
            'title' => "Recall {$lot->code}", 'description' => $data['reason']], $request->user());
        $lot->update(['hold_reason' => $data['reason']]);
        Farm::log($request->user(), "Recalled milk lot {$lot->code} (quality {$case->label()})", '/dairy?tab=trace');

        return back()->with('success', "{$lot->code} recalled.".($buyers ? " Notify: {$buyers}." : ''));
    }

    /**
     * Dispatch or issue to processing, from a Released lot only (BR-012); a sale posts revenue once.
     * D-195 (journey F): a sale passes the storage / transport checks (milk at or below the farm's maximum °C, clean
     * container, vehicle fit), may fill a customer's milk order (its price and terms), gets an invoice and is paid now or
     * owed on credit (Accounts receivable) until collected.
     */
    public function dispatch(Request $request, MilkLot $lot): RedirectResponse
    {
        abort_unless($this->quality($request) || $request->user()->role === 'finance', 403);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['sale', 'processing'])], 'litres' => ['required', 'numeric', 'gt:0'], 'dispatched_on' => ['required', 'date', 'before_or_equal:today'],
            'order_id' => ['nullable', 'exists:milk_orders,id'], 'customer_id' => ['nullable', 'exists:customers,id'], 'buyer' => ['nullable', 'string', 'max:120'],
            'price_per_litre' => ['nullable', 'numeric', 'gt:0'], 'terms' => ['nullable', Rule::in(['cash', 'credit'])], 'credit_days' => ['nullable', 'integer', 'between:1,120'],
            'dispatch_temp_c' => ['nullable', 'required_if:kind,sale', 'numeric', 'between:-5,40'], 'container_ok' => ['nullable', 'required_if:kind,sale', 'boolean'], 'vehicle_ok' => ['nullable', 'required_if:kind,sale', 'boolean'],
        ], ['dispatch_temp_c.required_if' => 'Take the milk temperature at loading.'], ['dispatch_temp_c' => 'milk temperature']);
        if ($lot->status !== 'released') {
            throw ValidationException::withMessages(['litres' => "{$lot->code} is ".MilkLot::STATUS[$lot->status].': only Released milk can be dispatched.']);
        }
        if ($data['litres'] > $lot->remaining() + 0.001) {
            throw ValidationException::withMessages(['litres' => "Only {$lot->remaining()} L left in {$lot->code}."]);
        }
        $order = ! empty($data['order_id']) ? \App\Models\MilkOrder::findOrFail($data['order_id']) : null;
        if ($data['kind'] === 'sale') {
            $max = (float) (\App\Support\Tenant::org()?->milk_dispatch_max_c ?? 7);
            $fail = array_filter([
                (float) $data['dispatch_temp_c'] > $max ? "milk at {$data['dispatch_temp_c']} °C (must be {$max} °C or colder)" : null,
                ! $data['container_ok'] ? 'container not clean' : null, ! $data['vehicle_ok'] ? 'vehicle not fit' : null,
            ]);
            if ($fail) {
                throw ValidationException::withMessages(['dispatch_temp_c' => 'Storage / transport check failed: '.implode(', ', $fail).'. Fix it before loading.']);
            }
            if ($order) {
                abort_unless($order->status === 'open', 422, 'This milk order is not open.');
                if ($data['litres'] > $order->remaining() + 0.001) {
                    throw ValidationException::withMessages(['litres' => "The order needs only {$order->remaining()} L more."]);
                }
            } elseif (empty($data['price_per_litre'])) {
                throw ValidationException::withMessages(['price_per_litre' => 'A sale needs the price per litre (or choose a milk order).']);
            }
        }
        DB::transaction(function () use ($lot, $data, $request, $order) {
            $customer = $order?->customer ?? (! empty($data['customer_id']) ? Customer::find($data['customer_id']) : null);
            $buyer = $customer?->name ?? ($order?->buyer ?? ($data['buyer'] ?? null));
            $price = $data['kind'] === 'sale' ? ($order ? $order->price_per_litre_minor : (int) round($data['price_per_litre'] * 100)) : null;
            $terms = $order?->terms ?? ($data['terms'] ?? 'cash');
            $days = $order?->credit_days ?? ($data['credit_days'] ?? 30);
            $invoice = null;
            if ($price) {
                DB::statement('LOCK TABLE milk_dispatches IN SHARE ROW EXCLUSIVE MODE');
                $invoice = 'MINV-'.now()->format('ym').'-'.str_pad((string) (MilkDispatch::where('invoice_no', 'like', 'MINV-'.now()->format('ym').'-%')->count() + 1), 3, '0', STR_PAD_LEFT);
            }
            $sale = $price ? Movements::sale([
                'sold_on' => $data['dispatched_on'], 'category' => 'product', 'item' => "Goat milk {$data['litres']} L · lot {$lot->code}", 'buyer' => $buyer,
                'gross' => $data['litres'] * $price / 100, 'customer_id' => $customer?->id, 'receipt_ref' => $invoice, 'payment_status' => $terms === 'credit' ? 'pending' : 'paid',
            ], $request->user(), "Goat milk {$data['litres']} L · lot {$lot->code} · {$invoice}") : null;
            MilkDispatch::create(['lot_id' => $lot->id, 'dispatched_on' => $data['dispatched_on'], 'kind' => $data['kind'], 'customer_id' => $customer?->id, 'buyer' => $order ? $order->buyer : ($data['buyer'] ?? null),
                'litres' => $data['litres'], 'price_per_litre_minor' => $price, 'sale_id' => $sale?->id, 'recorded_by' => $request->user()->name, 'order_id' => $order?->id,
                'dispatch_temp_c' => $data['dispatch_temp_c'] ?? null, 'container_ok' => $data['container_ok'] ?? null, 'vehicle_ok' => $data['vehicle_ok'] ?? null, 'invoice_no' => $invoice,
                'due_on' => $price && $terms === 'credit' ? \Illuminate\Support\Carbon::parse($data['dispatched_on'])->addDays($days)->toDateString() : null, 'collected_at' => $price && $terms !== 'credit' ? now() : null]);
            if ($lot->remaining() <= 0.001) {
                $lot->update(['status' => 'dispatched']);
            }
            if ($order && $order->remaining() <= 0.001) {
                $order->update(['status' => 'fulfilled']);
            }
        });

        return back()->with('success', "{$data['litres']} L of {$lot->code} dispatched.");
    }

    /** D-195: a customer milk order (traced to the lots it is filled from). */
    public function order(Request $request): RedirectResponse
    {
        abort_unless($this->quality($request) || $request->user()->role === 'finance', 403);
        $data = $request->validate([
            'customer_id' => ['nullable', 'required_without:buyer', 'exists:customers,id'], 'buyer' => ['nullable', 'string', 'max:120'], 'litres' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'price_per_litre' => ['required', 'numeric', 'gt:0', 'max:100000'], 'wanted_on' => ['required', 'date'], 'terms' => ['required', Rule::in(['cash', 'credit'])],
            'credit_days' => ['nullable', 'required_if:terms,credit', 'integer', 'between:1,120'], 'notes' => ['nullable', 'string', 'max:300'],
        ], ['customer_id.required_without' => 'Choose the customer (or type the buyer).']);
        $order = DB::transaction(function () use ($data, $request) {
            DB::statement('LOCK TABLE milk_orders IN SHARE ROW EXCLUSIVE MODE');
            $prefix = 'MO-'.now()->format('ym').'-';

            return \App\Models\MilkOrder::create(['order_no' => $prefix.str_pad((string) (\App\Models\MilkOrder::where('order_no', 'like', "$prefix%")->count() + 1), 3, '0', STR_PAD_LEFT),
                'customer_id' => $data['customer_id'] ?? null, 'buyer' => $data['buyer'] ?? null, 'litres' => $data['litres'], 'price_per_litre_minor' => (int) round($data['price_per_litre'] * 100),
                'wanted_on' => $data['wanted_on'], 'terms' => $data['terms'], 'credit_days' => $data['terms'] === 'credit' ? $data['credit_days'] : null, 'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->name]);
        });

        return back()->with('success', "Milk order {$order->order_no} saved. Fill it from a Released lot.");
    }

    public function cancelOrder(Request $request, \App\Models\MilkOrder $order): RedirectResponse
    {
        abort_unless($this->quality($request) || $request->user()->role === 'finance', 403);
        abort_unless($order->status === 'open', 422);
        $order->update(['status' => 'cancelled']);

        return back()->with('success', "{$order->order_no} cancelled.");
    }

    /** D-195: the customer pays a milk invoice: Accounts receivable is cleared. */
    public function collect(Request $request, MilkDispatch $dispatch): RedirectResponse
    {
        abort_unless($this->quality($request) || $request->user()->role === 'finance', 403);
        abort_unless($dispatch->sale_id && ! $dispatch->collected_at, 422, 'Nothing to collect.');
        $data = $request->validate(['collected_on' => ['required', 'date', 'before_or_equal:today']]);
        DB::transaction(function () use ($dispatch, $data, $request) {
            $entry = \App\Models\LedgerEntry::find($dispatch->sale?->ledger_entry_id);
            if ($entry) {
                \App\Support\Accounting::collect($entry, null, $request->user()->name, $data['collected_on']);
                $entry->update(['payment_status' => 'paid']);
            }
            $dispatch->update(['collected_at' => $data['collected_on']]);
        });

        return back()->with('success', "Payment for {$dispatch->invoice_no} received.");
    }

    /** D-196: a storage temperature reading; above the limit the lot is held under a quality case. */
    public function temperature(Request $request, MilkLot $lot): RedirectResponse
    {
        abort_unless($this->quality($request) || $request->user()->role === 'caretaker', 403);
        $c = (float) $request->validate(['temp_c' => ['required', 'numeric', 'between:-5,40']], [], ['temp_c' => 'temperature'])['temp_c'];
        $case = \App\Support\MilkTrace::temperature($lot, $c, $request->user());

        return back()->with('success', $case ? "{$lot->code} at {$c} °C is above the limit: held under quality {$case->label()}." : "Temperature {$c} °C recorded for {$lot->code}.");
    }

    /** D-196: a customer complaint about milk sold; the quality lead may recall the lot at once. */
    public function complaint(Request $request, MilkDispatch $dispatch): RedirectResponse
    {
        abort_unless($this->quality($request) || $request->user()->role === 'finance', 403);
        $data = $request->validate(['details' => ['required', 'string', 'max:1000'], 'severity' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])], 'recall' => ['required', 'boolean']]);
        abort_if($data['recall'] && ! $this->quality($request), 403, 'Only the quality lead recalls a lot.');
        $case = \App\Support\MilkTrace::complaint($dispatch, $data['details'], $data['severity'], (bool) $data['recall'], $request->user());

        return back()->with('success', "Complaint recorded as quality {$case->label()}".($data['recall'] ? '; the lot is recalled and its buyers listed.' : '.'));
    }

    /** D-196: refund or credit a sale of a recalled lot; the books are corrected and returned litres noted. */
    public function settle(Request $request, MilkDispatch $dispatch): RedirectResponse
    {
        abort_unless($this->quality($request) || $request->user()->role === 'finance', 403);
        $data = $request->validate(['kind' => ['required', Rule::in(['refund', 'credit'])], 'amount' => ['nullable', 'numeric', 'gt:0'], 'returned_l' => ['nullable', 'numeric', 'min:0']]);
        \App\Support\MilkTrace::settle($dispatch, $data['kind'], isset($data['amount']) ? (float) $data['amount'] : null, isset($data['returned_l']) ? (float) $data['returned_l'] : null, $request->user());

        return back()->with('success', "{$dispatch->invoice_no} ".($data['kind'] === 'credit' ? 'credited' : 'refunded').'.');
    }
}
