<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Customer;
use App\Models\FatteningBatch;
use App\Models\FatteningBatchAnimal;
use App\Models\HealthCase;
use App\Models\MeatSelection;
use App\Models\Pen;
use App\Models\RationPlan;
use App\Models\WeightRecord;
use App\Support\Farm;
use App\Support\Fattening;
use App\Support\Intake;
use App\Support\Movements;
use App\Support\Withdrawal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-187: App 18 Fattening & Meat Production, following V3 flow 18:
 * select candidates / feeders → verify identity and intake health (in quarantine or under treatment: hold, the vet
 * reviews; cleared → join) → create finishing batch + pen → set target weight, days and ration → feed and water
 * daily (Feeding) → weigh, track ADG and BCS → weight or welfare exception? revise feeding and health plan, reassess →
 * target weight reached? → vet meat-withdrawal clearance (vet only; blocked while a withdrawal runs, BR-011/BR-019) →
 * offer / reserve / price → scan on dispatch and post margin (the sale goes through Movements once; the goat's feed
 * cost is allocated once at exit with its basis, BR-020/BR-032).
 */
class FatteningController extends Controller
{
    private function supervisor(Request $request): bool
    {
        return in_array($request->user()->role, ['ceo', 'manager'], true);
    }

    public function index(Request $request): Response
    {
        $batches = FatteningBatch::orderByRaw("case status when 'closed' then 1 else 0 end")->orderByDesc('started_on')->get();

        return Inertia::render('Fattening', [
            'batches' => $batches->map(fn (FatteningBatch $b) => Fattening::batch($b))->values(),
            'candidates' => Fattening::candidates(),
            'pens' => Pen::with('shed')->where('kind', 'pen')->get()->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName()])->sortBy('name')->values(),
            // a ration plan belongs to a feeding group; it is shown as "<group> · from <date>"
            'rations' => RationPlan::with('group')->where('status', 'approved')->orderByDesc('effective_from')->get() // only rations the vet approved
                ->map(fn (RationPlan $r) => ['id' => $r->id, 'name' => ($r->group?->name ?? 'Ration').' · from '.$r->effective_from?->format('d/m/Y')])->values(),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'options' => ['status' => FatteningBatch::STATUS, 'memberStatus' => FatteningBatchAnimal::STATUS],
            'canManage' => $this->supervisor($request), 'isVet' => $request->user()->passes('clinical'),
            // D-197: meat selections (journey H): open ones, then the last decisions
            'selections' => MeatSelection::with('animal.breed')->orderByRaw("case status when 'proposed' then 0 when 'approved' then 1 else 2 end")->latest('id')->limit(60)->get()
                ->map(fn (MeatSelection $s) => ['id' => $s->id, 'goatId' => $s->animal_id, 'number' => $s->animal?->farm_number, 'breed' => $s->animal?->breed?->name, 'sex' => $s->animal?->sex,
                    'purpose' => $s->animal?->animal_purpose, 'reason' => $s->reason, 'notes' => $s->notes, 'status' => $s->status, 'proposedBy' => $s->proposed_by, 'proposedAt' => $s->created_at?->format('d/m/Y'),
                    'decidedBy' => $s->decided_by, 'decidedAt' => $s->decided_at?->format('d/m/Y'), 'decisionNote' => $s->decision_note,
                    'mine' => $s->proposed_by === $request->user()->name])->values(),
            'proposable' => Animal::active()->orderBy('farm_number')->get(['id', 'farm_number', 'sex', 'animal_purpose'])
                ->map(fn (Animal $a) => ['id' => $a->id, 'number' => $a->farm_number, 'sex' => $a->sex, 'purpose' => $a->animal_purpose])->values(),
            'reasons' => MeatSelection::REASONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->supervisor($request), 403, 'The owner or manager creates finishing batches.');
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'], 'pen_id' => ['nullable', 'exists:pens,id'], 'ration_plan_id' => ['nullable', 'exists:ration_plans,id'],
            'started_on' => ['required', 'date', 'before_or_equal:today'], 'target_weight_kg' => ['required', 'numeric', 'between:10,200'], 'target_days' => ['required', 'integer', 'between:7,730'],
            'animal_ids' => ['required', 'array', 'min:1'], 'animal_ids.*' => ['uuid', 'distinct', 'exists:animals,id'], 'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['animal_ids' => 'goats']);
        $result = DB::transaction(function () use ($data, $request) {
            DB::statement('LOCK TABLE fattening_batches IN SHARE ROW EXCLUSIVE MODE');
            $batch = FatteningBatch::create(collect($data)->except('animal_ids')->all() + ['code' => FatteningBatch::nextCode(), 'status' => 'active', 'created_by' => $request->user()->name]);
            $held = [];
            foreach (Animal::whereIn('id', $data['animal_ids'])->get() as $a) {
                if ($why = $this->hold($a)) { // verify identity and intake health
                    $held[] = "{$a->farm_number} ({$why})";

                    continue;
                }
                $this->join($batch, $a, $request->user());
            }

            return [$batch, $held];
        });
        [$batch, $held] = $result;
        Farm::log($request->user(), "Created finishing batch {$batch->code}", '/fattening');

        return back()->with('success', "{$batch->code} created.".($held ? ' Held for vet review: '.implode(', ', $held).'.' : ''));
    }

    /** Why a goat cannot join yet (quarantine or open case → hold, open quarantine or vet review), or null. */
    private function hold(Animal $a): ?string
    {
        if ($a->status !== 'active') {
            return 'not on the farm';
        }
        if (in_array($a->animal_purpose, Fattening::BREEDING_STOCK, true)) {
            return 'breeding stock: needs an approved meat selection';
        }
        if (FatteningBatchAnimal::where('animal_id', $a->id)->whereIn('status', ['finishing', 'ready', 'reserved'])->exists()) {
            return 'already in a batch';
        }
        if ($w = \App\Support\WelfareCare::restriction($a->id)) {
            return "welfare case {$w->label()}"; // D-199
        }
        $cases = HealthCase::where('animal_id', $a->id)->whereIn('status', HealthCase::ACTIVE)->get();
        if ($cases->contains('condition', Intake::CONDITION)) {
            return 'intake quarantine';
        }

        return $cases->isNotEmpty() ? 'open health case' : null;
    }

    private function join(FatteningBatch $batch, Animal $a, $user): void
    {
        $weight = Fattening::latestWeight($a->id);
        FatteningBatchAnimal::create([
            // joins on the batch start date when created with it, or today when added later
            'batch_id' => $batch->id, 'animal_id' => $a->id, 'joined_on' => $batch->wasRecentlyCreated ? $batch->started_on : now()->startOfDay(),
            'entry_weight_kg' => $weight, 'entry_cost_minor' => $a->purchase_cost_minor ?? ($a->purchase_rate_minor_per_kg && $weight ? (int) round($a->purchase_rate_minor_per_kg * $weight) : null),
        ]);
        $a->update(['animal_purpose' => in_array($a->animal_purpose, ['sale', 'meat'], true) ? $a->animal_purpose : 'fattening']);
        if ($batch->pen_id && (int) $a->pen_id !== (int) $batch->pen_id) {
            Movements::transfer($a->fresh(), $batch->pen, $user, ['reason' => "Finishing batch {$batch->code}"]);
        }
        MeatSelection::where('animal_id', $a->id)->whereIn('status', ['proposed', 'approved'])->update(['status' => 'batched', 'batch_id' => $batch->id]);
        Farm::event($a, 'origin', "Joined finishing batch {$batch->code}".($weight ? " at {$weight} KG" : ''), $user->name);
    }

    public function add(Request $request, FatteningBatch $batch): RedirectResponse
    {
        abort_unless($this->supervisor($request), 403);
        abort_if($batch->status === 'closed', 422, 'This batch is closed.');
        $data = $request->validate(['animal_ids' => ['required', 'array', 'min:1'], 'animal_ids.*' => ['uuid', 'exists:animals,id']]);
        $held = [];
        DB::transaction(function () use ($batch, $data, $request, &$held) {
            foreach (Animal::whereIn('id', $data['animal_ids'])->get() as $a) {
                if ($why = $this->hold($a)) {
                    $held[] = "{$a->farm_number} ({$why})";

                    continue;
                }
                $this->join($batch, $a, $request->user());
            }
        });

        return back()->with('success', 'Goats added.'.($held ? ' Held: '.implode(', ', $held).'.' : ''));
    }

    public function plan(Request $request, FatteningBatch $batch): RedirectResponse
    {
        abort_unless($this->supervisor($request) || $request->user()->passes('clinical'), 403);
        $data = $request->validate([
            'target_weight_kg' => ['required', 'numeric', 'between:10,200'], 'target_days' => ['required', 'integer', 'between:7,730'],
            'ration_plan_id' => ['nullable', 'exists:ration_plans,id'], 'pen_id' => ['nullable', 'exists:pens,id'], 'notes' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(['active', 'sale_hold'])],
        ]);
        $batch->update($data);

        return back()->with('success', "Plan for {$batch->code} revised.");
    }

    /** Daily weighing of the batch (scale or manual); BR-018: impossible readings are refused. */
    public function weigh(Request $request, FatteningBatch $batch): RedirectResponse
    {
        $data = $request->validate(['measured_on' => ['required', 'date', 'before_or_equal:today'], 'weights' => ['required', 'array'], 'weights.*.member_id' => ['required', 'exists:fattening_batch_animals,id'],
            'weights.*.kg' => ['nullable', 'numeric', 'between:3,200'], 'weights.*.bcs' => ['nullable', 'integer', 'between:1,5']]);
        $n = 0;
        DB::transaction(function () use ($data, $batch, $request, &$n) {
            foreach ($data['weights'] as $row) {
                if (empty($row['kg'])) {
                    continue;
                }
                $m = FatteningBatchAnimal::where('batch_id', $batch->id)->findOrFail($row['member_id']);
                $prev = Fattening::latestWeight($m->animal_id);
                if ($prev && abs($row['kg'] - $prev) > max(8, $prev * 0.25)) {
                    throw ValidationException::withMessages(['weights' => "{$m->animal->farm_number}: {$row['kg']} KG is far from the last weight {$prev} KG. Weigh again before saving."]);
                }
                WeightRecord::create(['animal_id' => $m->animal_id, 'measured_on' => $data['measured_on'], 'weight_kg' => $row['kg'], 'body_score' => $row['bcs'] ?? null, 'context' => 'fattening', 'measured_by' => $request->user()->name]);
                $n++;
            }
        });

        return back()->with('success', "{$n} weight(s) saved.");
    }

    /** Vet meat-withdrawal clearance: only a vet, only when no withdrawal runs and no case is open. */
    public function clear(Request $request, FatteningBatchAnimal $member): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'The vet gives the meat-withdrawal clearance.');
        $a = $member->animal;
        if ($meat = Withdrawal::forAnimal($a->id)['meat']) {
            throw ValidationException::withMessages(['member' => "{$a->farm_number} is under meat withdrawal until {$meat->format('d/m/Y')}."]);
        }
        abort_if(HealthCase::where('animal_id', $a->id)->whereIn('status', HealthCase::ACTIVE)->exists(), 422, "{$a->farm_number} has an open health case.");
        $member->update(['status' => 'ready', 'cleared_by' => $request->user()->name, 'cleared_at' => now()]);
        Farm::event($a, 'health', 'Vet clearance for sale (no meat withdrawal)', $request->user()->name);

        return back()->with('success', "{$a->farm_number} cleared for sale.");
    }

    public function reserve(Request $request, FatteningBatchAnimal $member): RedirectResponse
    {
        abort_unless($this->supervisor($request) || $request->user()->role === 'finance', 403);
        abort_unless($member->status === 'ready', 422, 'Only a goat cleared by the vet can be reserved.');
        $member->update(['status' => 'reserved']);

        return back()->with('success', "{$member->animal->farm_number} reserved.");
    }

    /**
     * Scan on dispatch and post margin. The tag scanned must be this goat (BR-026); the sale goes through Movements
     * (which refuses a meat withdrawal and enters the sale once) and the feed cost is allocated now with its basis.
     */
    public function sell(Request $request, FatteningBatchAnimal $member): RedirectResponse
    {
        abort_unless($this->supervisor($request) || $request->user()->role === 'finance', 403);
        abort_unless(in_array($member->status, ['ready', 'reserved'], true), 422, 'Only a cleared goat can be sold.');
        $data = $request->validate([
            'scanned' => ['required', 'string', 'max:80'], 'price' => ['required', 'numeric', 'min:1'], 'exit_weight_kg' => ['nullable', 'numeric', 'between:3,200'],
            'buyer' => ['nullable', 'string', 'max:120'], 'customer_id' => ['nullable', 'exists:customers,id'], 'sold_on' => ['required', 'date', 'before_or_equal:today'],
        ], [], ['scanned' => 'scanned tag']);
        $a = $member->animal;
        $code = strtoupper(trim($data['scanned']));
        $epc = \App\Models\RfidAssignment::where('animal_id', $a->id)->whereNull('valid_to')->with('tag')->first()?->tag?->tag_code;
        if ($code !== strtoupper($a->farm_number) && $code !== strtoupper((string) $epc)) {
            throw ValidationException::withMessages(['scanned' => "The scanned tag is not {$a->farm_number}. Loading stopped."]);
        }
        abort_if($member->batch->status === 'sale_hold', 422, "{$member->batch->code} is on Sale Hold.");
        DB::transaction(function () use ($member, $a, $data, $request) {
            $customer = ! empty($data['customer_id']) ? Customer::find($data['customer_id']) : null;
            if (! empty($data['exit_weight_kg'])) {
                WeightRecord::create(['animal_id' => $a->id, 'measured_on' => $data['sold_on'], 'weight_kg' => $data['exit_weight_kg'], 'context' => 'sale', 'measured_by' => $request->user()->name]);
                $member->update(['exit_weight_kg' => $data['exit_weight_kg']]);
            }
            // D-197: Movements enters the sale once and closes this batch record with its costs (feed, health) and revenue
            Movements::exit($a, 'sale', $request->user(), [
                'moved_on' => $data['sold_on'], 'destination' => $customer?->name ?? ($data['buyer'] ?? null), 'amount' => $data['price'], 'sale_category' => 'live_goat',
                'reason' => "Finishing batch {$member->batch->code}", 'evidence_ref' => "Dispatch scan {$data['scanned']}", 'customer_id' => $customer?->id,
            ]);
        });

        return back()->with('success', "{$a->farm_number} sold and dispatched. Margin posted to the batch.");
    }

    /**
     * D-197 (journey H): propose a goat for the meat business: a breeder cull, a feeder or a slow grower. Refused for a
     * pregnant doe, a goat already finishing, or one with an open selection.
     */
    public function propose(Request $request): RedirectResponse
    {
        $data = $request->validate(['animal_id' => ['required', 'uuid', 'exists:animals,id'], 'reason' => ['required', Rule::in(array_keys(MeatSelection::REASONS))],
            'notes' => ['nullable', 'string', 'max:500']], [], ['animal_id' => 'goat']);
        $a = Animal::findOrFail($data['animal_id']);
        $why = match (true) {
            $a->status !== 'active' => 'is not on the farm',
            Fattening::openMember($a->id) !== null => 'is already in a finishing batch',
            MeatSelection::where('animal_id', $a->id)->whereIn('status', ['proposed', 'approved'])->exists() => 'already has an open meat selection',
            $a->sex === 'female' && ($a->repro_status === 'pregnant' || \App\Models\Mating::where('dam_id', $a->id)->where('status', 'pregnant')->exists()) => 'is pregnant: wait until she kids',
            default => null,
        };
        if ($why) {
            throw ValidationException::withMessages(['animal_id' => "{$a->farm_number} {$why}."]);
        }
        MeatSelection::create($data + ['proposed_by' => $request->user()->name]);
        Farm::log($request->user(), "Proposed {$a->farm_number} for meat", '/fattening?tab=selection');

        return back()->with('success', "{$a->farm_number} proposed for meat. The owner or manager decides.");
    }

    /** Approve or reject: the owner or a manager, never the person who proposed it (the owner may decide their own). */
    public function decide(Request $request, MeatSelection $selection): RedirectResponse
    {
        abort_unless($this->supervisor($request), 403, 'The owner or manager approves meat selections.');
        abort_unless($selection->status === 'proposed', 422, 'This selection was already decided.');
        $user = $request->user();
        if ($selection->proposed_by === $user->name && $user->role !== 'ceo') {
            throw ValidationException::withMessages(['decision' => 'Someone other than the proposer must approve this.']);
        }
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'note' => ['nullable', 'required_if:decision,rejected', 'string', 'max:300']], [], ['note' => 'reason']);
        $a = $selection->animal;
        DB::transaction(function () use ($selection, $data, $user, $a) {
            $selection->update(['status' => $data['decision'], 'decided_by' => $user->name, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null]);
            if ($data['decision'] === 'approved') {
                $a->update(['animal_purpose' => 'fattening']);
                Farm::event($a, 'origin', 'Selected for meat ('.MeatSelection::REASONS[$selection->reason].'), approved by '.$user->name, $user->name);
            }
        });

        return back()->with('success', $data['decision'] === 'approved' ? "{$a->farm_number} approved for meat: now a finishing candidate." : "Meat selection of {$a->farm_number} rejected.");
    }
}
