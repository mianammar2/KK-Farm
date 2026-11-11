<?php

namespace App\Http\Controllers;

use App\Models\FeedItem;
use App\Models\FeedLot;
use App\Models\FeedStockMovement;
use App\Models\Organization;
use App\Support\Farm;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-209: App 06 Feed Safety (BR-016, UAT-17). Every purchase or receipt is a lot. A feed marked "needs safety test"
 * arrives quarantined: its KG cannot be fed or mixed. Someone samples it (moisture, aflatoxin, appearance); a second
 * person (owner, manager, vet; not the receiver) releases it only within the farm's limits, or rejects it and the lot
 * is written off.
 */
class FeedLotController extends Controller
{
    private function decider(Request $request): bool
    {
        return in_array($request->user()->role, ['ceo', 'manager', 'vet'], true);
    }

    public function index(Request $request): Response
    {
        $org = Organization::find(Tenant::current());
        $lots = FeedLot::with('item')->orderByRaw("status = 'quarantined' desc")->orderByDesc('received_on')->orderByDesc('id')->limit(200)->get();

        return Inertia::render('FeedLots', [
            'lots' => $lots->map(fn (FeedLot $l) => ['id' => $l->id, 'no' => $l->lot_no, 'item' => $l->item?->name, 'on' => $l->received_on->format('j M Y'), 'kg' => $l->qty_kg, 'supplier' => $l->supplier,
                'status' => $l->status, 'moisture' => $l->moisture_pct, 'aflatoxin' => $l->aflatoxin_ppb, 'appearance' => $l->appearance, 'sample' => $l->sample_ref, 'testedBy' => $l->tested_by,
                'receivedBy' => $l->received_by, 'decidedBy' => $l->decided_by, 'note' => $l->decision_note, 'tested' => $l->tested_at !== null]),
            'items' => FeedItem::where('active', true)->orderBy('name')->get(['id', 'name', 'safety_test']),
            'limits' => ['moisture' => (float) $org->feed_max_moisture_pct, 'aflatoxin' => (float) $org->feed_max_aflatoxin_ppb],
            'kpi' => ['quarantined' => $lots->where('status', 'quarantined')->count(), 'heldKg' => round($lots->where('status', 'quarantined')->sum('qty_kg'), 1),
                'rejected' => $lots->where('status', 'rejected')->where('received_on', '>=', now()->subDays(90))->count()],
            'canDecide' => $this->decider($request), 'canManage' => $request->user()->passes('manage_feed_store') || $this->decider($request), 'me' => $request->user()->name,
        ]);
    }

    public function test(Request $request, FeedLot $lot): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_feed_store') || $this->decider($request), 403);
        abort_unless($lot->status === 'quarantined', 422, 'Only a quarantined lot is tested.');
        $data = $request->validate(['moisture_pct' => ['required', 'numeric', 'between:0,100'], 'aflatoxin_ppb' => ['nullable', 'numeric', 'between:0,10000'],
            'appearance' => ['nullable', 'string', 'max:200'], 'sample_ref' => ['nullable', 'string', 'max:60']], [], ['moisture_pct' => 'moisture', 'aflatoxin_ppb' => 'aflatoxin']);
        $lot->update($data + ['tested_by' => $request->user()->name, 'tested_at' => now()]);

        return back()->with('success', "{$lot->lot_no}: results recorded. A second person releases or rejects it.");
    }

    public function decide(Request $request, FeedLot $lot): RedirectResponse
    {
        abort_unless($this->decider($request), 403, 'The owner, manager or vet releases or rejects feed lots.');
        abort_unless($lot->status === 'quarantined', 422, 'This lot was already decided.');
        $user = $request->user();
        $data = $request->validate(['decision' => ['required', Rule::in(['released', 'rejected'])], 'note' => ['nullable', 'required_if:decision,rejected', 'string', 'max:300']], [], ['note' => 'reason']);
        if ($lot->received_by === $user->name && $user->role !== 'ceo') {
            throw ValidationException::withMessages(['decision' => 'Someone other than the person who received the lot decides.']);
        }
        if ($data['decision'] === 'released') {
            $org = Organization::find(Tenant::current());
            $why = match (true) {
                ! $lot->tested_at => 'Record the test results first.',
                $lot->moisture_pct > (float) $org->feed_max_moisture_pct => "Moisture {$lot->moisture_pct}% is above the {$org->feed_max_moisture_pct}% limit.",
                $lot->aflatoxin_ppb !== null && $lot->aflatoxin_ppb > (float) $org->feed_max_aflatoxin_ppb => "Aflatoxin {$lot->aflatoxin_ppb} ppb is above the {$org->feed_max_aflatoxin_ppb} ppb limit.",
                default => null,
            };
            if ($why) {
                throw ValidationException::withMessages(['decision' => $why]);
            }
        }
        DB::transaction(function () use ($lot, $data, $user) {
            $off = $data['decision'] === 'rejected' ? FeedStockMovement::create(['feed_item_id' => $lot->feed_item_id, 'moved_on' => now()->toDateString(), 'type' => 'write_off', 'qty_kg' => -$lot->qty_kg,
                'reference' => $lot->lot_no, 'notes' => mb_substr('Rejected feed lot: '.$data['note'], 0, 200), 'recorded_by' => $user->name]) : null;
            $lot->update(['status' => $data['decision'], 'decided_by' => $user->name, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null, 'write_off_movement_id' => $off?->id]);
            Tenant::flush();
        });
        Farm::log($user, ($data['decision'] === 'released' ? 'Released' : 'Rejected')." feed lot {$lot->lot_no}", '/feeding/lots');

        return back()->with('success', $data['decision'] === 'released' ? "{$lot->lot_no} released: it can be fed and mixed." : "{$lot->lot_no} rejected and written off. Return it to the supplier or dispose of it.");
    }

    public function item(Request $request, FeedItem $item): RedirectResponse
    {
        abort_unless($this->decider($request) || $request->user()->passes('manage_feed_store'), 403);
        $data = $request->validate(['safety_test' => ['required', 'boolean']]);
        $item->update($data);

        return back()->with('success', $item->name.($data['safety_test'] ? ': new lots wait for a safety test.' : ': new lots are released on arrival.'));
    }

    public function limits(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['ceo', 'vet'], true), 403);
        $data = $request->validate(['feed_max_moisture_pct' => ['required', 'numeric', 'between:5,40'], 'feed_max_aflatoxin_ppb' => ['required', 'numeric', 'between:0,500']]);
        Organization::whereKey(Tenant::current())->update($data);

        return back()->with('success', 'Feed safety limits saved.');
    }
}
