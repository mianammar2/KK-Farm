<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\AnimalPurchaseItem;
use App\Models\AnimalPurchaseLot;
use App\Models\Breed;
use App\Models\Department;
use App\Models\HealthCase;
use App\Models\HealthRecord;
use App\Models\LedgerEntry;
use App\Models\Pen;
use App\Models\Supplier;
use App\Models\Task;
use App\Support\Farm;
use App\Support\FieldCatalog;
use App\Support\GoatRegistration;
use App\Support\Intake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-190: V3 cross-app journey A, Purchase-to-Quarantine (flow 24):
 * goat purchase request approved → select market lot and register the purchase → receive, count and inspect goats →
 * origin IDs and clinical screening OK? No: flag discrepancy / sick case (reconcile). Yes: generate Animal IDs and link
 * EPC tags → assign a dedicated quarantine pen → observe and apply the vet protocol (Intake & Quarantine) → veterinary
 * clearance? No: extend quarantine / treat / investigate, then recheck. Yes: transfer into an approved operating pen →
 * post cost, supplier bill and animal inventory → live herd dashboard updated.
 * Key control: no direct mixing before clearance (Intake::assertNoMixing); the lot source and purchase price stay on
 * every goat (animals.purchase_lot_id, purchase_cost_minor).
 */
class AnimalPurchaseController extends Controller
{
    private function approver(Request $request): bool
    {
        return in_array($request->user()->role, ['ceo', 'manager'], true);
    }

    public function index(Request $request): Response
    {
        $lots = AnimalPurchaseLot::with(['supplier', 'items.animal', 'items.breed', 'quarantinePen.shed'])->orderByRaw("case status when 'requested' then 0 when 'approved' then 1 when 'received' then 2 else 3 end")->orderByDesc('id')->limit(100)->get();
        $quarantined = Intake::quarantinedIds();
        $items = AnimalPurchaseItem::whereIn('lot_id', $lots->where('status', 'received')->pluck('id'))->get();
        $accepted = $items->where('screening', '!=', 'rejected');
        $closed = HealthCase::where('condition', Intake::CONDITION)->where('status', 'closed')->whereNotNull('resolved_on')->where('resolved_on', '>=', now()->subDays(180)->toDateString())->get();
        $tagged = $accepted->whereNotNull('animal_id')->filter(fn ($i) => \App\Models\RfidAssignment::where('animal_id', $i->animal_id)->whereNull('valid_to')->exists())->count();
        $penGoats = Animal::active()->whereNotNull('pen_id')->get(['id', 'pen_id'])->groupBy('pen_id');

        return Inertia::render('AnimalPurchase', [
            'lots' => $lots->map(fn (AnimalPurchaseLot $l) => [
                'id' => $l->id, 'no' => $l->lot_no, 'kind' => $l->kind, 'supplier' => $l->supplier?->name, 'source' => $l->source, 'purpose' => $l->purpose, 'expected' => $l->expected_head,
                'budget' => $l->budget_minor / 100, 'status' => $l->status, 'requestedBy' => $l->requested_by, 'approvedBy' => $l->approved_by, 'note' => $l->decision_note,
                'requestedAt' => $l->created_at->format('d/m/Y'), 'receivedOn' => $l->received_on?->format('d/m/Y'), 'receivedBy' => $l->received_by, 'pen' => $l->quarantinePen?->fullName(),
                'paid' => $l->paid_minor !== null ? $l->paid_minor / 100 : null, 'transport' => $l->transport_minor !== null ? $l->transport_minor / 100 : null,
                'discrepancy' => $l->discrepancy, 'reconciledBy' => $l->reconciled_by, 'reconciliation' => $l->reconciliation, 'billPosted' => $l->ledger_entry_id !== null,
                'billStatus' => $l->ledger_entry_id ? LedgerEntry::find($l->ledger_entry_id)?->payment_status : null,
                'items' => $l->items->map(fn (AnimalPurchaseItem $i) => [
                    'id' => $i->id, 'origin' => $i->origin_ref, 'sex' => $i->sex, 'breed' => $i->breed?->name, 'weight' => $i->weight_kg, 'screening' => $i->screening, 'notes' => $i->notes,
                    'goat' => $i->animal ? ['id' => $i->animal_id, 'number' => $i->animal->farm_number, 'inQuarantine' => in_array($i->animal_id, $quarantined, true), 'status' => $i->animal->status, 'pen' => $i->animal->pen?->fullName()] : null,
                    'cost' => $i->cost_minor !== null ? $i->cost_minor / 100 : null,
                ])->values(),
                'canDecide' => $l->status === 'requested' && $this->approver($request) && ($l->requested_by !== $request->user()->name || $request->user()->role === 'ceo'),
            ]),
            'kpi' => [
                'rejection' => $items->count() ? round($items->where('screening', 'rejected')->count() / $items->count() * 100, 1) : null,
                'quarantineDays' => $closed->count() ? round($closed->avg(fn ($c) => $c->onset_on->diffInDays($c->resolved_on)), 1) : null,
                'identity' => $accepted->count() ? round($tagged / $accepted->count() * 100) : null,
                'costPerHead' => $accepted->whereNotNull('cost_minor')->count() ? round($accepted->whereNotNull('cost_minor')->avg('cost_minor') / 100) : null,
                'inQuarantine' => count($quarantined),
            ],
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(['id', 'name']),
            'breeds' => Breed::orderBy('name')->get(['id', 'name']),
            // A dedicated quarantine pen: empty, or holding only goats that are themselves in intake quarantine.
            'pens' => Pen::with('shed')->where('kind', 'pen')->get()->map(function (Pen $p) use ($penGoats, $quarantined) {
                $ids = ($penGoats[$p->id] ?? collect())->pluck('id')->all();

                return ['id' => $p->id, 'name' => $p->fullName(), 'goats' => count($ids), 'quarantineOnly' => ! array_diff($ids, $quarantined), 'biosecure' => in_array($p->shed?->type, ['BSU', 'VCU'], true)];
            })->filter(fn ($p) => $p['quarantineOnly'])->sortBy([['biosecure', 'desc'], ['name', 'asc']])->values(),
            'options' => ['status' => AnimalPurchaseLot::STATUS, 'screening' => AnimalPurchaseItem::SCREENING],
            'canApprove' => $this->approver($request), 'canReceive' => in_array($request->user()->role, ['ceo', 'manager', 'vet'], true),
            'canTag' => $request->user()->passes('assign_tags'),
            'quarantineDays' => Intake::days(),
        ]);
    }

    /** Goat purchase request (market lot or breeder), waiting for approval. */
    public function request(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('request_goat_purchase'), 403, 'Only the Administrator and the farm manager raise a goat purchase.'); // D-264 F-06
        $data = $request->validate([
            'supplier_id' => ['nullable', 'exists:suppliers,id'], 'source' => ['required', 'string', 'max:120'], 'purpose' => ['nullable', 'string', 'max:160'],
            'expected_head' => ['required', 'integer', 'between:1,2000'], 'budget' => ['required', 'numeric', 'min:1', 'max:1000000000'],
            'kind' => ['nullable', \Illuminate\Validation\Rule::in(['breeding', 'feeder'])], // D-211
        ], [], ['source' => 'market or breeder', 'expected_head' => 'number of goats', 'budget' => 'agreed price']);
        $lot = DB::transaction(function () use ($data, $request) {
            DB::statement('LOCK TABLE animal_purchase_lots IN SHARE ROW EXCLUSIVE MODE');
            $prefix = 'APL-'.now()->format('Y').'-';
            $n = (int) AnimalPurchaseLot::where('lot_no', 'like', $prefix.'%')->count() + 1;

            return AnimalPurchaseLot::create([
                'lot_no' => $prefix.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'supplier_id' => $data['supplier_id'] ?? null, 'source' => $data['source'], 'purpose' => $data['purpose'] ?? null,
                'expected_head' => $data['expected_head'], 'budget_minor' => (int) round($data['budget'] * 100), 'status' => 'requested', 'requested_by' => $request->user()->name,
                'kind' => $data['kind'] ?? 'breeding',
            ]);
        });
        Farm::log($request->user(), "Requested goat purchase {$lot->lot_no}", '/purchase/animals');

        return back()->with('success', "{$lot->lot_no} requested. The owner or manager approves it.");
    }

    /** Approve or reject: never the requester (BR-027), except the owner. */
    public function decide(Request $request, AnimalPurchaseLot $lot): RedirectResponse
    {
        abort_unless($this->approver($request), 403, 'The owner or manager approves goat purchases.');
        abort_unless($lot->status === 'requested', 422, 'This request was already decided.');
        abort_if($lot->requested_by === $request->user()->name && $request->user()->role !== 'ceo', 403, 'Someone other than the requester approves the purchase.');
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'required_if:approve,false', 'required_if:approve,0', 'string', 'max:300']], ['note.required_if' => 'Say why it is not approved.']);
        $lot->update(['status' => $data['approve'] ? 'approved' : 'rejected', 'approved_by' => $request->user()->name, 'approved_at' => now(), 'decision_note' => $data['note'] ?? null]);

        return back()->with('success', $data['approve'] ? "{$lot->lot_no} approved. Receive and inspect the goats when they arrive." : "{$lot->lot_no} not approved.");
    }

    public function cancel(Request $request, AnimalPurchaseLot $lot): RedirectResponse
    {
        abort_unless($this->approver($request) || $lot->requested_by === $request->user()->name, 403);
        abort_unless(in_array($lot->status, ['requested', 'approved'], true), 422, 'Only a lot not yet received is cancelled.');
        $lot->update(['status' => 'cancelled']);

        return back()->with('success', "{$lot->lot_no} cancelled.");
    }

    /**
     * Receive, count and inspect. Accepted goats (healthy or sick) get Animal IDs and EPC tags in the dedicated quarantine
     * pen; a sick one also goes to the vet as a reported concern; rejected ones go back to the seller. A count that does
     * not match, or a goat without an origin ID, is a discrepancy to reconcile. Cost and supplier bill post once.
     */
    public function receive(Request $request, AnimalPurchaseLot $lot): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['ceo', 'manager', 'vet'], true), 403, 'The owner, manager or vet receives goats.');
        abort_unless($lot->status === 'approved', 422, 'Only an approved purchase is received.');
        $data = $request->validate([
            'received_on' => ['required', 'date', 'before_or_equal:today'], 'quarantine_pen_id' => ['required', Rule::exists('pens', 'id')->where('kind', 'pen')],
            'paid' => ['required', 'numeric', 'min:0', 'max:1000000000'], 'transport' => ['nullable', 'numeric', 'min:0', 'max:100000000'], 'bill_paid' => ['required', 'boolean'],
            'discrepancy' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:500'], 'items.*.origin_ref' => ['nullable', 'string', 'max:40'], 'items.*.sex' => ['required', Rule::in(['male', 'female'])],
            'items.*.breed_id' => ['required', 'exists:breeds,id'], 'items.*.weight_kg' => ['nullable', 'numeric', 'between:0.5,150'],
            'items.*.screening' => ['required', Rule::in(array_keys(AnimalPurchaseItem::SCREENING))], 'items.*.notes' => ['nullable', 'string', 'max:300'],
            'items.*.rfid_epc' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'], 'items.*.tag_id' => ['nullable', 'string', 'max:40'],
        ], ['items.*.notes.required' => 'Say what is wrong.'], ['quarantine_pen_id' => 'quarantine pen']);
        $items = collect($data['items']);
        foreach ($items as $i => $it) {
            if ($it['screening'] !== 'ok' && empty($it['notes'])) {
                throw ValidationException::withMessages(["items.$i.notes" => $it['screening'] === 'sick' ? 'Describe the signs for the vet.' : 'Say why it is rejected.']);
            }
        }
        $accepted = $items->filter(fn ($it) => $it['screening'] !== 'rejected');
        if ($accepted->isEmpty() && (float) $data['paid'] > 0) {
            throw ValidationException::withMessages(['paid' => 'Every goat was rejected: nothing is paid to the seller.']);
        }
        // Origin IDs and count: anything off is a discrepancy that has to be written down (and later reconciled).
        $issues = array_filter([
            $items->count() !== $lot->expected_head ? "Counted {$items->count()} goats, expected {$lot->expected_head}." : null,
            $accepted->contains(fn ($it) => empty($it['origin_ref'])) ? 'Some goats have no origin ID.' : null,
            $items->contains('screening', 'sick') ? 'Sick goats on arrival.' : null,
            $items->contains('screening', 'rejected') ? 'Goats rejected at inspection.' : null,
        ]);
        if ($issues && empty($data['discrepancy'])) {
            throw ValidationException::withMessages(['discrepancy' => 'Flag the discrepancy: '.implode(' ', $issues)]);
        }
        $pen = Pen::findOrFail($data['quarantine_pen_id']);
        $others = Animal::active()->where('pen_id', $pen->id)->pluck('id')->all();
        if (array_diff($others, Intake::quarantinedIds())) {
            throw ValidationException::withMessages(['quarantine_pen_id' => "{$pen->fullName()} holds herd goats. Choose an empty pen or one with only goats in quarantine."]);
        }
        $paid = (int) round($data['paid'] * 100);
        $transport = (int) round(($data['transport'] ?? 0) * 100);
        $perHead = $accepted->count() ? intdiv($paid + $transport, $accepted->count()) : 0;
        $user = $request->user();
        $rules = GoatRegistration::rules(FieldCatalog::forAnimal());

        DB::transaction(function () use ($lot, $data, $items, $pen, $paid, $transport, $perHead, $user, $issues, $rules) {
            $supplier = $lot->supplier?->name ?? $lot->source;
            foreach ($items as $i => $it) {
                $animal = null;
                if ($it['screening'] !== 'rejected') {
                    $reg = array_filter([
                        'origin' => 'purchased', 'breed_id' => (int) $it['breed_id'], 'sex' => $it['sex'], 'acquired_on' => $data['received_on'], 'pen_id' => $pen->id,
                        'source_farm' => mb_substr($supplier, 0, 60), 'purchase_cost_pkr' => $perHead / 100, 'latest_weight_kg' => $it['weight_kg'] ?? null,
                        'rfid_epc' => $it['rfid_epc'] ?? null, 'tag_id' => isset($it['tag_id']) && $it['tag_id'] !== '' ? strtoupper(trim($it['tag_id'])) : null,
                        'notes' => trim(($it['origin_ref'] ?? '') !== '' ? "Origin ID {$it['origin_ref']} · lot {$lot->lot_no}" : "Lot {$lot->lot_no}"),
                    ], fn ($v) => $v !== null && $v !== '');
                    $v = Validator::make($reg, array_intersect_key($rules, $reg + array_filter($rules, fn ($r) => in_array('required', $r, true))));
                    if ($v->fails()) {
                        throw ValidationException::withMessages(collect($v->errors()->messages())->mapWithKeys(fn ($m, $k) => ["items.$i.".($k === 'farm_number' ? 'tag_id' : $k) => $m[0]])->all());
                    }
                    $animal = GoatRegistration::register($v->validated(), $user);
                    $animal->update(['purchase_lot_id' => $lot->id] + ($lot->kind === 'feeder' ? ['animal_purpose' => 'fattening'] : [])); // D-211: feeders are bought for meat
                    if ($it['screening'] === 'sick') {
                        HealthRecord::create([
                            'animal_id' => $animal->id, 'kind' => 'observation', 'occurred_on' => $data['received_on'], 'title' => mb_substr("Sick on arrival ({$lot->lot_no})", 0, 120),
                            'notes' => $it['notes'], 'severity' => 'high', 'recorded_by' => $user->name, 'recorded_role' => 'Intake', 'status' => 'open',
                        ]);
                    }
                }
                AnimalPurchaseItem::create(['lot_id' => $lot->id, 'origin_ref' => $it['origin_ref'] ?? null, 'sex' => $it['sex'], 'breed_id' => $it['breed_id'], 'weight_kg' => $it['weight_kg'] ?? null,
                    'screening' => $it['screening'], 'notes' => $it['notes'] ?? null, 'animal_id' => $animal?->id, 'cost_minor' => $animal ? $perHead : null]);
            }
            // Post cost and supplier bill once (Accounting: Goat purchases).
            $status = $data['bill_paid'] ? 'paid' : 'pending';
            $bill = $paid > 0 ? LedgerEntry::create(['direction' => 'out', 'category' => 'Animal purchase', 'amount' => $paid / 100, 'occurred_on' => $data['received_on'], 'counterparty' => $supplier,
                'description' => "Goat purchase {$lot->lot_no}: ".$items->where('screening', '!=', 'rejected')->count().' goats', 'recorded_by' => $user->name, 'payment_status' => $status]) : null;
            $freight = $transport > 0 ? LedgerEntry::create(['direction' => 'out', 'category' => 'Animal purchase', 'amount' => $transport / 100, 'occurred_on' => $data['received_on'], 'counterparty' => 'Transport',
                'description' => "Transport for {$lot->lot_no}", 'recorded_by' => $user->name, 'payment_status' => $status]) : null;
            $lot->update(['status' => 'received', 'received_on' => $data['received_on'], 'received_by' => $user->name, 'quarantine_pen_id' => $pen->id, 'paid_minor' => $paid, 'transport_minor' => $transport,
                'discrepancy' => $issues ? trim(($data['discrepancy'] ?? '').' ['.implode(' ', $issues).']') : null, 'ledger_entry_id' => $bill?->id, 'transport_ledger_entry_id' => $freight?->id]);
            if ($issues) {
                Task::create(['title' => mb_substr("Reconcile goat purchase {$lot->lot_no}", 0, 190), 'type' => 'other', 'priority' => 'medium', 'status' => 'open', 'due_on' => now()->addDays(2)->toDateString(),
                    'department_id' => Department::where('name', 'Management')->value('id'), 'notes' => mb_substr(implode(' ', $issues).' '.($data['discrepancy'] ?? ''), 0, 500)]);
            }
            Farm::log($user, "Received goat purchase {$lot->lot_no}", '/purchase/animals');
        });

        $n = $accepted->count();

        return back()->with('success', "{$lot->lot_no}: {$n} goats registered into quarantine in {$pen->fullName()}.".($issues ? ' Discrepancy flagged for reconciliation.' : ''));
    }

    public function reconcile(Request $request, AnimalPurchaseLot $lot): RedirectResponse
    {
        abort_unless($this->approver($request), 403, 'The owner or manager reconciles the purchase.');
        abort_unless($lot->status === 'received' && $lot->discrepancy && ! $lot->reconciled_by, 422, 'Nothing to reconcile.');
        $data = $request->validate(['reconciliation' => ['required', 'string', 'max:1000']], ['reconciliation.required' => 'Say how it was settled (refund, replacement, documents received…).']);
        $lot->update(['reconciled_by' => $request->user()->name, 'reconciliation' => $data['reconciliation']]);
        Task::where('title', "Reconcile goat purchase {$lot->lot_no}")->whereIn('status', Task::OPEN)->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $request->user()->name]);

        return back()->with('success', "{$lot->lot_no} reconciled.");
    }
}
