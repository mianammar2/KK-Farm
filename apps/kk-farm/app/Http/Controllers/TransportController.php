<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\SaleOrder;
use App\Models\SaleOrderLine;
use App\Models\TransportCheck;
use App\Models\TransportClaim;
use App\Models\TransportTrip;
use App\Models\Vehicle;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-203: journey N Transport-to-Delivery (V3 flow 37). Sales → Vet → Housing → Logistics → Finance: a confirmed
 * order and an approved vehicle → fitness to travel per goat (unfit goats come off the order) → loading by scan and
 * dispatch (SaleOrderController, which now requires the trip) with a gate pass → welfare checks on the road →
 * delivery receipt; any loss or shortage opens a customer claim, decided by the owner or manager.
 */
class TransportController extends Controller
{
    private function supervisor(Request $request): bool
    {
        return in_array($request->user()->role, ['ceo', 'manager'], true);
    }

    private function crew(Request $request): bool
    {
        return $request->user()->passes('manage_sales') || $this->supervisor($request) || $request->user()->canModule('scan');
    }

    public function index(Request $request): Response
    {
        $trips = TransportTrip::with(['order.customer', 'order.lines.animal', 'vehicle', 'checks', 'claims'])->orderByRaw("case status when 'planned' then 0 when 'in_transit' then 1 else 2 end")->orderByDesc('id')->limit(80)->get();
        $since = now()->subDays(90);
        $done = TransportTrip::with(['checks', 'claims'])->whereNotNull('departed_at')->where('departed_at', '>=', $since)->get();
        $loaded = (int) $done->sum('head_loaded');

        return Inertia::render('Transport', [
            'trips' => $trips->map(fn (TransportTrip $t) => [
                'id' => $t->id, 'no' => $t->trip_no, 'order' => $t->order?->order_no, 'orderStatus' => $t->order?->status, 'customer' => $t->order?->customer?->name ?? $t->order?->buyer,
                'vehicle' => $t->vehicle?->reg_no, 'capacity' => $t->vehicle?->capacity_head, 'vehicleOk' => (bool) $t->vehicle?->usable(), 'driver' => $t->driver, 'destination' => $t->destination, 'km' => $t->distance_km,
                'status' => $t->status, 'gatePass' => $t->gate_pass_no, 'departed' => $t->departed_at?->format('d/m g:i A'), 'loaded' => $t->head_loaded,
                'delivered' => $t->delivered_at?->format('d/m g:i A'), 'received' => $t->head_received, 'receivedBy' => $t->received_by, 'receipt' => $t->receipt_notes,
                'goats' => $t->order ? $t->order->lines->whereIn('status', ['reserved', 'loaded', 'sold'])->map(fn (SaleOrderLine $l) => ['id' => $l->id, 'number' => $l->animal?->farm_number, 'status' => $l->status,
                    'fit' => $l->fit_ok, 'fitBy' => $l->fit_by, 'note' => $l->fit_note])->values() : [],
                'checks' => $t->checks->map(fn ($c) => ['at' => $c->checked_at->format('d/m g:i A'), 'where' => $c->location, 'down' => $c->down, 'injured' => $c->injured, 'dead' => $c->dead, 'watered' => $c->watered, 'temp' => $c->temp_c, 'notes' => $c->notes, 'by' => $c->checked_by])->values(),
                'claims' => $t->claims->map(fn ($c) => ['id' => $c->id, 'kind' => $c->kind, 'head' => $c->head, 'details' => $c->details, 'claimed' => $c->claimed_minor, 'status' => $c->status, 'credit' => $c->credit_minor, 'by' => $c->decided_by, 'note' => $c->decision_note])->values(),
            ]),
            'vehicles' => Vehicle::orderBy('reg_no')->get()->map(fn (Vehicle $v) => ['id' => $v->id, 'reg' => $v->reg_no, 'kind' => $v->kind, 'capacity' => $v->capacity_head, 'ownership' => $v->ownership, 'driver' => $v->driver,
                'phone' => $v->phone, 'inspected' => $v->inspected_on?->format('d/m/Y'), 'inspection' => $v->inspection ?? [], 'approved' => $v->approved, 'usable' => $v->usable(), 'active' => $v->active]),
            'orders' => SaleOrder::with('customer')->where('status', 'reserved')->whereDoesntHave('trip')->orderBy('id')->get()->map(fn ($o) => ['id' => $o->id, 'no' => $o->order_no, 'customer' => $o->customer?->name ?? $o->buyer,
                'head' => $o->lines()->whereIn('status', ['reserved', 'loaded'])->count()]),
            'kpi' => [
                'lossPct' => $loaded ? round($done->sum(fn ($t) => $t->checks->sum('dead')) / $loaded * 100, 2) : null, 'dead' => (int) $done->sum(fn ($t) => $t->checks->sum('dead')),
                'mismatches' => $done->filter(fn ($t) => $t->head_received !== null && $t->head_received !== $t->head_loaded)->count(), 'trips' => $done->count(),
                'openClaims' => TransportClaim::where('status', 'open')->count(), 'inTransit' => TransportTrip::where('status', 'in_transit')->count(),
            ],
            'options' => ['kinds' => Vehicle::KINDS, 'points' => Vehicle::POINTS, 'claims' => TransportClaim::KINDS],
            'canPlan' => $request->user()->passes('manage_sales') || $this->supervisor($request), 'canDecide' => $this->supervisor($request),
            'canFit' => $request->user()->passes('clinical') || in_array($request->user()->role, ['ceo', 'manager', 'caretaker'], true), 'canCrew' => $this->crew($request),
        ]);
    }

    /** Register or re-inspect a vehicle. Approved only when every inspection point passes. */
    public function vehicle(Request $request, ?Vehicle $vehicle = null): RedirectResponse
    {
        abort_unless($this->supervisor($request), 403, 'The owner or manager approves vehicles.');
        $data = $request->validate(['reg_no' => ['required', 'string', 'max:30'], 'kind' => ['required', Rule::in(array_keys(Vehicle::KINDS))], 'capacity_head' => ['required', 'integer', 'between:1,500'],
            'ownership' => ['required', Rule::in(['own', 'hired'])], 'driver' => ['nullable', 'string', 'max:80'], 'phone' => ['nullable', 'string', 'max:30'],
            'inspection' => ['present', 'array'], 'inspection.*' => [Rule::in(array_keys(Vehicle::POINTS))], 'active' => ['boolean']]);
        $data['reg_no'] = strtoupper(trim($data['reg_no']));
        $passed = ! array_diff(array_keys(Vehicle::POINTS), $data['inspection']);
        $values = $data + ['inspected_on' => now()->toDateString(), 'inspected_by' => $request->user()->name, 'approved' => $passed];
        $vehicle ? $vehicle->update($values) : Vehicle::create($values);

        return back()->with('success', $passed ? "{$data['reg_no']} inspected and approved for 90 days." : "{$data['reg_no']} saved but not approved: every inspection point must pass.");
    }

    /** Plan the trip of a confirmed order on an approved vehicle big enough for it. */
    public function plan(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sales') || $this->supervisor($request), 403);
        $data = $request->validate(['sale_order_id' => ['required', 'exists:sale_orders,id'], 'vehicle_id' => ['required', 'exists:vehicles,id'], 'driver' => ['nullable', 'string', 'max:80'],
            'destination' => ['required', 'string', 'max:160'], 'distance_km' => ['nullable', 'integer', 'between:0,5000']]);
        $order = SaleOrder::findOrFail($data['sale_order_id']);
        $vehicle = Vehicle::findOrFail($data['vehicle_id']);
        abort_unless($order->status === 'reserved', 422, 'Only a confirmed (approved) order is transported.');
        if (! $vehicle->usable()) {
            throw ValidationException::withMessages(['vehicle_id' => "{$vehicle->reg_no} is not approved (or its inspection is over 90 days old)."]);
        }
        $head = $order->lines()->whereIn('status', ['reserved', 'loaded'])->count();
        if ($head > $vehicle->capacity_head) {
            throw ValidationException::withMessages(['vehicle_id' => "{$vehicle->reg_no} carries {$vehicle->capacity_head}; this order has {$head} goats."]);
        }
        if (! ($data['driver'] ?? $vehicle->driver)) {
            throw ValidationException::withMessages(['driver' => 'Name the driver.']);
        }
        DB::transaction(function () use ($data, $vehicle, $request) {
            DB::statement('LOCK TABLE transport_trips IN SHARE ROW EXCLUSIVE MODE');
            $no = 'TRP-'.now()->format('ym').'-'.str_pad((string) (TransportTrip::where('trip_no', 'like', 'TRP-'.now()->format('ym').'-%')->count() + 1), 3, '0', STR_PAD_LEFT);
            TransportTrip::create($data + ['trip_no' => $no, 'driver' => $data['driver'] ?? $vehicle->driver, 'created_by' => $request->user()->name]);
        });

        return back()->with('success', 'Trip planned. Check each goat is fit to travel before loading.');
    }

    /** Fitness to travel (vet, owner, manager or caretaker). An unfit goat comes off the order. */
    public function fitness(Request $request, SaleOrderLine $line): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical') || in_array($request->user()->role, ['ceo', 'manager', 'caretaker'], true), 403, 'The vet or a caretaker checks fitness to travel.');
        abort_unless(in_array($line->status, ['reserved', 'loaded'], true), 422, 'This goat is not waiting to load.');
        $data = $request->validate(['fit' => ['required', 'boolean'], 'note' => ['nullable', 'required_if:fit,false', 'string', 'max:300']], [], ['note' => 'reason']);
        DB::transaction(function () use ($line, $data, $request) {
            $line->update(['fit_ok' => $data['fit'], 'fit_by' => $request->user()->name, 'fit_at' => now(), 'fit_note' => $data['note'] ?? null]
                + ($data['fit'] ? [] : ['status' => 'removed', 'removed_reason' => 'Unfit to travel: '.$data['note']]));
            if ($line->animal) {
                Farm::event($line->animal, 'health', $data['fit'] ? 'Fit to travel' : "Not fit to travel: {$data['note']}", $request->user()->name);
            }
        });

        return back()->with('success', $data['fit'] ? 'Fit to travel.' : 'Unfit: taken off the order.');
    }

    /** A welfare check on the road. */
    public function check(Request $request, TransportTrip $trip): RedirectResponse
    {
        abort_unless($this->crew($request), 403);
        abort_unless($trip->status === 'in_transit', 422, 'Checks are made while the goats are on the road.');
        $data = $request->validate(['location' => ['nullable', 'string', 'max:120'], 'down' => ['required', 'integer', 'min:0'], 'injured' => ['required', 'integer', 'min:0'], 'dead' => ['required', 'integer', 'min:0'],
            'watered' => ['boolean'], 'temp_c' => ['nullable', 'numeric', 'between:-10,60'], 'notes' => ['nullable', 'string', 'max:500']]);
        $deadSoFar = (int) $trip->checks()->sum('dead');
        if ($deadSoFar + $data['dead'] > $trip->head_loaded) {
            throw ValidationException::withMessages(['dead' => "Only {$trip->head_loaded} goats were loaded."]);
        }
        TransportCheck::create($data + ['trip_id' => $trip->id, 'checked_at' => now(), 'checked_by' => $request->user()->name, 'watered' => (bool) ($data['watered'] ?? false)]);

        return back()->with('success', $data['dead'] || $data['down'] || $data['injured'] ? 'Check saved. Losses are claimed at delivery.' : 'Check saved: all goats fine.');
    }

    /** The buyer's receipt. A shortage, a death or an injury opens a claim. */
    public function deliver(Request $request, TransportTrip $trip): RedirectResponse
    {
        abort_unless($this->crew($request), 403);
        abort_unless($trip->status === 'in_transit', 422, 'This trip is not on the road.');
        $data = $request->validate(['head_received' => ['required', 'integer', 'min:0', 'max:'.$trip->head_loaded], 'injured' => ['required', 'integer', 'min:0'], 'received_by' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'], 'claimed' => ['nullable', 'numeric', 'min:0']], [], ['head_received' => 'goats received']);
        $user = $request->user();
        $claims = DB::transaction(function () use ($trip, $data, $user) {
            $trip->update(['status' => 'delivered', 'delivered_at' => now(), 'head_received' => $data['head_received'], 'received_by' => $data['received_by'], 'receipt_notes' => $data['notes'] ?? null]);
            $dead = (int) $trip->checks()->sum('dead');
            $missing = $trip->head_loaded - $data['head_received'];
            $claimed = ! empty($data['claimed']) ? (int) round($data['claimed'] * 100) : null;
            $made = [];
            foreach (array_filter(['death' => min($dead, $missing), 'shortage' => max(0, $missing - $dead), 'injury' => $data['injured']]) as $kind => $head) {
                $made[] = TransportClaim::create(['trip_id' => $trip->id, 'kind' => $kind, 'head' => $head, 'opened_by' => $user->name, 'claimed_minor' => count($made) === 0 ? $claimed : null,
                    'details' => TransportClaim::KINDS[$kind].": {$head} of {$trip->head_loaded}, received by {$data['received_by']}".(! empty($data['notes']) ? ". {$data['notes']}" : '')]);
            }

            return $made;
        });
        Farm::log($user, "Delivered {$trip->trip_no}", '/transport');

        return back()->with('success', $claims ? 'Delivered with '.count($claims).' claim(s) for the owner or manager to decide.' : 'Delivered in full.');
    }

    /** Accept (with a credit to the customer, posted once) or reject a claim. */
    public function claim(Request $request, TransportClaim $claim): RedirectResponse
    {
        abort_unless($this->supervisor($request), 403, 'The owner or manager decides claims.');
        abort_unless($claim->status === 'open', 422, 'This claim was already decided.');
        $data = $request->validate(['decision' => ['required', Rule::in(['accepted', 'rejected'])], 'credit' => ['nullable', 'required_if:decision,accepted', 'numeric', 'min:0'],
            'note' => ['nullable', 'required_if:decision,rejected', 'string', 'max:300']]);
        DB::transaction(function () use ($claim, $data, $request) {
            $credit = $data['decision'] === 'accepted' ? (int) round($data['credit'] * 100) : null;
            $trip = $claim->trip ?? TransportTrip::find($claim->trip_id);
            $ledger = $credit ? LedgerEntry::create(['direction' => 'out', 'category' => 'Transit Claims', 'amount' => $credit / 100, 'occurred_on' => now()->toDateString(),
                'counterparty' => $trip->order?->customer?->name ?? $trip->order?->buyer, 'description' => "Claim on {$trip->trip_no} ({$trip->order?->order_no}): ".TransportClaim::KINDS[$claim->kind],
                'recorded_by' => $request->user()->name, 'payment_status' => 'pending']) : null;
            $claim->update(['status' => $data['decision'], 'credit_minor' => $credit, 'ledger_entry_id' => $ledger?->id, 'decided_by' => $request->user()->name, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null]);
        });

        return back()->with('success', 'Claim '.$data['decision'].'.');
    }
}
