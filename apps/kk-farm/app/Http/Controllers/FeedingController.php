<?php

namespace App\Http\Controllers;

use App\Models\FeedingGroup;
use App\Models\FeedItem;
use App\Models\FeedStockMovement;
use App\Models\FeedUsage;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RationPlan;
use App\Support\Farm;
use App\Support\Feed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** D-054: feed store, ration plans, feeding per pen and meal, and automatic feeding compliance. */
class FeedingController extends Controller
{
    private const ATTRIBUTES = [
        'lines' => 'feeds', 'lines.*.feed_item_id' => 'feed', 'lines.*.kg_per_head' => 'kg per goat', 'lines.*.offered_kg' => 'kg given',
        'lines.*.refused_kg' => 'leftover', 'qty_kg' => 'quantity', 'cost_per_kg' => 'cost per kg', 'reorder_level_kg' => 'reorder level', 'opening_kg' => 'stock now',
    ];

    public function index(Request $request): Response
    {
        $org = Feed::org();
        $today = Feed::now($org)->toDateString();
        $meal = in_array($request->query('meal'), array_keys(Feed::MEALS), true) ? $request->query('meal') : Feed::currentMeal($org);
        $items = FeedItem::orderBy('code')->get();
        $itemsById = $items->keyBy('id');
        $stock = Feed::stock();
        $board = Feed::board($today, $org);
        $user = $request->user();

        $weekUse = FeedUsage::current()->where('fed_on', '>=', Carbon::parse($today)->subDays(6)->toDateString())
            ->groupBy('feed_item_id')->selectRaw('feed_item_id, sum(offered_kg) as kg')->pluck('kg', 'feed_item_id');

        $pens = Pen::with('shed')->get()->keyBy('id');
        $line = fn (int $itemId, float $planned, ?FeedUsage $u) => [
            'feedItemId' => $itemId, 'code' => $itemsById[$itemId]->code ?? '?', 'name' => $itemsById[$itemId]->name ?? 'Unknown feed', 'planned' => $planned,
            'usage' => $u ? ['id' => $u->id, 'offered' => $u->offered_kg, 'refused' => $u->refused_kg, 'by' => $u->fed_by, 'at' => $u->fed_at->setTimezone($org->timezone)->format('g:i A')] : null,
        ];

        return Inertia::render('Feeding', [
            'today' => Carbon::parse($today)->format('l j M'),
            'meal' => $meal,
            'meals' => Feed::MEALS,
            'mealTimes' => Feed::mealTimes($org),
            'settings' => ['late' => $org->meal_late_minutes, 'window' => $org->meal_window_minutes, 'tolerance' => $org->feed_tolerance_pct, 'timezone' => $org->timezone],
            'board' => $board->map(function (array $meals, int $penId) use ($pens, $meal, $line) {
                $m = $meals[$meal];
                $rows = $m['rows']->keyBy('feed_item_id');
                $ids = collect(array_keys($m['lines']))->merge($rows->keys())->unique();

                return [
                    'id' => $penId, 'name' => $pens[$penId]->name ?? 'Pen', 'shed' => $pens[$penId]?->shed?->name, 'headcount' => $m['headcount'], 'unplanned' => $m['unplanned'],
                    'plannedKg' => $m['plannedKg'], 'offeredKg' => round($m['rows']->sum('offered_kg'), 2), 'status' => $m['status'],
                    'meals' => collect($meals)->map(fn ($x) => $x['status']),
                    'lines' => $ids->map(fn ($id) => $line($id, (float) ($m['lines'][$id] ?? 0), $rows[$id] ?? null))->values(),
                ];
            })->sortBy([['shed', 'asc'], ['name', 'asc']])->values(),
            'groups' => $this->groups(),
            'items' => $items->map(fn (FeedItem $i) => [
                'id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'category' => $i->category, 'costPerKg' => $i->cost_per_kg_minor / 100, 'reorder' => $i->reorder_level_kg,
                'supplier' => $i->supplier, 'notes' => $i->notes, 'active' => $i->active, 'stock' => $stock[$i->id] ?? 0,
                'value' => round(max(0, $stock[$i->id] ?? 0) * $i->cost_per_kg_minor / 100), 'status' => Feed::stockStatus($stock[$i->id] ?? 0, $i->reorder_level_kg),
                'daysLeft' => ($weekUse[$i->id] ?? 0) > 0 ? (int) floor(max(0, $stock[$i->id] ?? 0) / ($weekUse[$i->id] / 7)) : null,
            ]),
            'movements' => FeedStockMovement::with('item')->orderByDesc('moved_on')->orderByDesc('id')->limit(15)->get()->map(fn (FeedStockMovement $m) => [
                'id' => $m->id, 'on' => $m->moved_on->format('j M'), 'item' => $m->item->name, 'type' => FeedStockMovement::TYPES[$m->type][0] ?? $m->type,
                'qty' => $m->qty_kg, 'rate' => $m->rate_minor !== null ? $m->rate_minor / 100 : null, 'party' => $m->party, 'reference' => $m->reference, 'by' => $m->recorded_by,
            ]),
            'history' => FeedUsage::current()->with(['item', 'pen'])->where('fed_on', '>=', Carbon::parse($today)->subDays(13)->toDateString())
                ->orderByDesc('fed_at')->limit(300)->get()->map(fn (FeedUsage $u) => [
                    'id' => $u->id, 'on' => $u->fed_on->format('j M'), 'meal' => $u->meal, 'pen' => $u->pen->name, 'feed' => $u->item->name, 'headcount' => $u->headcount,
                    'planned' => $u->planned_kg, 'offered' => $u->offered_kg, 'refused' => $u->refused_kg, 'consumed' => round($u->consumedKg(), 2),
                    'perHead' => $u->headcount ? round($u->consumedKg() / $u->headcount, 3) : null, 'cost' => round($u->offered_kg * $u->unit_cost_minor / 100),
                    'by' => $u->fed_by, 'corrected' => $u->supersedes_id !== null,
                    'off' => $u->planned_kg > 0 && abs($u->offered_kg - $u->planned_kg) / $u->planned_kg * 100 > $org->feed_tolerance_pct,
                ]),
            'categories' => FeedItem::CATEGORIES,
            'movementTypes' => collect(FeedStockMovement::TYPES)->map(fn ($t) => ['label' => $t[0], 'direction' => $t[1]]),
            'can' => [
                'store' => $user->passes('manage_feed_store'), 'plan' => $user->passes('plan_rations'),
                'approve' => $user->passes('approve_feeding_plan'), 'settings' => $user->canModule('settings'),
            ],
        ]);
    }

    private function groups(): array
    {
        $plans = RationPlan::with('lines.item')->orderBy('effective_from')->orderBy('id')->get()->groupBy('feeding_group_id');
        $today = Feed::now()->toDateString();
        $inForce = Feed::plansOn($today);
        $head = Feed::groupIds(\App\Models\Animal::active()->get())->countBy();
        $show = fn (?RationPlan $p) => $p ? [
            'id' => $p->id, 'from' => $p->effective_from->format('j M Y'), 'fromIso' => $p->effective_from->toDateString(), 'status' => $p->status,
            'split' => ['morning' => $p->split_morning, 'midday' => $p->split_midday, 'evening' => $p->split_evening],
            'lines' => $p->lines->map(fn ($l) => ['feedItemId' => $l->feed_item_id, 'name' => $l->item->name, 'code' => $l->item->code, 'kg' => $l->kg_per_head]),
            'kgPerHead' => round($p->lines->sum('kg_per_head'), 3),
            'costPerHead' => round($p->lines->sum(fn ($l) => $l->kg_per_head * $l->item->cost_per_kg_minor) / 100, 2),
            'notes' => $p->notes, 'createdBy' => $p->created_by, 'approvedBy' => $p->approved_by, 'approvedOn' => $p->approved_at?->format('j M Y'),
        ] : null;

        return FeedingGroup::orderBy('sort')->orderBy('id')->get()->map(function (FeedingGroup $g) use ($plans, $inForce, $head, $show, $today) {
            $mine = $plans[$g->id] ?? collect();

            return [
                'id' => $g->id, 'name' => $g->name, 'criteria' => $g->criteria, 'head' => $head[$g->id] ?? 0,
                'current' => $show($inForce[$g->id] ?? null),
                'upcoming' => $show($mine->first(fn ($p) => $p->status === 'approved' && $p->effective_from->toDateString() > $today)),
                'draft' => $show($mine->firstWhere('status', 'draft')),
            ];
        })->all();
    }

    /** Record what one pen was given at one meal. Re-saving the same pen and meal corrects it (a new row supersedes the old). */
    public function record(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pen_id' => ['required', 'integer', 'exists:pens,id'],
            'meal' => ['required', Rule::in(array_keys(Feed::MEALS))],
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.feed_item_id' => ['required', 'integer', 'distinct', 'exists:feed_items,id'],
            'lines.*.offered_kg' => ['required', 'numeric', 'min:0', 'max:100000'],
        ], [], self::ATTRIBUTES);
        $org = Feed::org();
        $today = Feed::now($org)->toDateString();
        $plan = Feed::plannedByPen($data['meal'], $today)[$data['pen_id']] ?? ['headcount' => 0, 'lines' => []];
        $pen = Pen::findOrFail($data['pen_id']);
        $costs = FeedItem::pluck('cost_per_kg_minor', 'id');

        DB::transaction(function () use ($data, $today, $plan, $costs, $request) {
            $current = FeedUsage::current()->where(['fed_on' => $today, 'meal' => $data['meal'], 'pen_id' => $data['pen_id']])->lockForUpdate()->get()->keyBy('feed_item_id');
            foreach ($data['lines'] as $l) {
                if (FeedUsage::whereKey($l['id'])->exists()) {
                    continue; // the same entry sent twice (offline retry)
                }
                $previous = $current[$l['feed_item_id']] ?? null;
                if (! $previous && (float) $l['offered_kg'] === 0.0) {
                    continue;
                }
                FeedUsage::create([
                    'id' => $l['id'], 'fed_on' => $today, 'meal' => $data['meal'], 'pen_id' => $data['pen_id'], 'feed_item_id' => $l['feed_item_id'],
                    'headcount' => $plan['headcount'], 'planned_kg' => $plan['lines'][$l['feed_item_id']] ?? 0, 'offered_kg' => $l['offered_kg'],
                    'refused_kg' => $previous?->refused_kg, 'unit_cost_minor' => $costs[$l['feed_item_id']] ?? 0,
                    'fed_at' => $previous?->fed_at ?? now(), 'fed_by' => $request->user()->name, 'supersedes_id' => $previous?->id,
                ]);
            }
        });
        Farm::log($request->user(), Feed::MEALS[$data['meal']]." feed recorded for {$pen->name}", '/feeding');

        return back()->with('success', "{$pen->name}: ".strtolower(Feed::MEALS[$data['meal']]).' feed saved.');
    }

    /** Leftover weighed after the meal. Stored as a correction of the usage row, never by editing it. */
    public function leftover(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.usage_id' => ['required', 'uuid', 'distinct', 'exists:feed_usages,id'],
            'lines.*.refused_kg' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ], [], self::ATTRIBUTES);

        DB::transaction(function () use ($data, $request) {
            foreach ($data['lines'] as $i => $l) {
                if (FeedUsage::whereKey($l['id'])->exists() || $l['refused_kg'] === null) {
                    continue;
                }
                $usage = FeedUsage::current()->whereKey($l['usage_id'])->lockForUpdate()->first();
                if (! $usage) {
                    throw ValidationException::withMessages(["lines.$i.refused_kg" => 'This entry was changed by someone else. Reload and try again.']);
                }
                if ($l['refused_kg'] > $usage->offered_kg) {
                    throw ValidationException::withMessages(["lines.$i.refused_kg" => 'Leftover cannot be more than the feed given.']);
                }
                FeedUsage::create(collect($usage->getAttributes())->except(['id', 'created_at', 'updated_at', 'supersedes_id', 'refused_kg'])->all() + [
                    'id' => $l['id'], 'refused_kg' => $l['refused_kg'], 'supersedes_id' => $usage->id,
                ]);
            }
        });
        Farm::log($request->user(), 'Recorded feed leftover', '/feeding');

        return back()->with('success', 'Leftover saved.');
    }

    public function storeItem(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_feed_store'), 403);
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9._-]+$/', 'unique:feed_items,code'],
            'name' => ['required', 'string', 'max:80'],
            'category' => ['required', Rule::in(array_keys(FeedItem::CATEGORIES))],
            'cost_per_kg' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'reorder_level_kg' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'opening_kg' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'supplier' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);

        DB::transaction(function () use ($data, $request) {
            $item = FeedItem::create([
                'code' => $data['code'], 'name' => $data['name'], 'category' => $data['category'], 'cost_per_kg_minor' => (int) round($data['cost_per_kg'] * 100),
                'reorder_level_kg' => $data['reorder_level_kg'] ?? 0, 'supplier' => $data['supplier'] ?? null, 'notes' => $data['notes'] ?? null,
            ]);
            if (($data['opening_kg'] ?? 0) > 0) {
                FeedStockMovement::create(['feed_item_id' => $item->id, 'moved_on' => Feed::now()->toDateString(), 'type' => 'opening', 'qty_kg' => $data['opening_kg'], 'rate_minor' => $item->cost_per_kg_minor, 'recorded_by' => $request->user()->name]);
            }
        });
        Farm::log($request->user(), "Added feed {$data['name']}", '/feeding');

        return back()->with('success', "{$data['name']} added to the feed store.");
    }

    public function updateItem(Request $request, FeedItem $item): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_feed_store'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'category' => ['required', Rule::in(array_keys(FeedItem::CATEGORIES))],
            'cost_per_kg' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'reorder_level_kg' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'supplier' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:200'],
            'active' => ['boolean'],
        ]);
        $item->update([
            'name' => $data['name'], 'category' => $data['category'], 'cost_per_kg_minor' => (int) round($data['cost_per_kg'] * 100), 'reorder_level_kg' => $data['reorder_level_kg'] ?? 0,
            'supplier' => $data['supplier'] ?? null, 'notes' => $data['notes'] ?? null, 'active' => $data['active'] ?? $item->active,
        ]);
        Farm::log($request->user(), "Updated feed {$item->name}", '/feeding');

        return back()->with('success', "{$item->name} saved.");
    }

    /** Receipts and adjustments. A purchase also records the expense, so it is entered once (D-054). */
    public function stock(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_feed_store'), 403);
        $data = $request->validate([
            'feed_item_id' => ['required', 'integer', 'exists:feed_items,id'],
            'type' => ['required', Rule::in(array_keys(FeedStockMovement::TYPES))],
            'direction' => ['nullable', Rule::in(['in', 'out'])],
            'qty_kg' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'rate' => ['nullable', 'required_if:type,purchase', 'numeric', 'min:0', 'max:1000000'],
            'moved_on' => ['required', 'date', 'before_or_equal:today'],
            'party' => ['nullable', 'string', 'max:80'],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);
        $fixed = FeedStockMovement::TYPES[$data['type']][1];
        if ($fixed === 0 && empty($data['direction'])) {
            throw ValidationException::withMessages(['direction' => 'Choose whether stock goes in or out.']);
        }
        $sign = $fixed !== 0 ? $fixed : ($data['direction'] === 'in' ? 1 : -1);
        $item = FeedItem::findOrFail($data['feed_item_id']);
        $rate = isset($data['rate']) ? (int) round($data['rate'] * 100) : null;

        DB::transaction(function () use ($data, $sign, $item, $rate, $request) {
            $ledger = null;
            if ($data['type'] === 'purchase' && $rate > 0) {
                $ledger = LedgerEntry::create([
                    'direction' => 'out', 'category' => 'Feed Purchase', 'amount' => round($data['qty_kg'] * $rate / 100, 2), 'occurred_on' => $data['moved_on'],
                    'counterparty' => $data['party'] ?? null, 'description' => "{$data['qty_kg']} kg {$item->name}".(! empty($data['reference']) ? " · {$data['reference']}" : ''),
                    'recorded_by' => $request->user()->name,
                ]);
                $item->update(['cost_per_kg_minor' => $rate]); // feed is costed at the latest purchase price from now on
            }
            FeedStockMovement::create([
                'feed_item_id' => $item->id, 'moved_on' => $data['moved_on'], 'type' => $data['type'], 'qty_kg' => $sign * $data['qty_kg'], 'rate_minor' => $rate,
                'party' => $data['party'] ?? null, 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
                'ledger_entry_id' => $ledger?->id, 'recorded_by' => $request->user()->name,
            ]);
        });
        Farm::log($request->user(), FeedStockMovement::TYPES[$data['type']][0].": {$data['qty_kg']} kg {$item->name}", '/feeding');

        return back()->with('success', 'Stock updated'.($data['type'] === 'purchase' ? ' and the expense recorded.' : '.'));
    }

    /** A new ration for a group. It is a draft until a veterinarian approves it; an existing draft is replaced. */
    public function storePlan(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('plan_rations'), 403);
        $data = $request->validate([
            'feeding_group_id' => ['required', 'integer', 'exists:feeding_groups,id'],
            'effective_from' => ['required', 'date', 'after_or_equal:'.Feed::now()->subDays(7)->toDateString()],
            'split_morning' => ['required', 'integer', 'between:0,100'],
            'split_midday' => ['required', 'integer', 'between:0,100'],
            'split_evening' => ['required', 'integer', 'between:0,100'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.feed_item_id' => ['required', 'integer', 'distinct', 'exists:feed_items,id'],
            'lines.*.kg_per_head' => ['required', 'numeric', 'gt:0', 'max:50'],
            'notes' => ['nullable', 'string', 'max:300'],
        ], ['lines.*.feed_item_id.distinct' => 'Each feed can appear only once.'], self::ATTRIBUTES);
        if ($data['split_morning'] + $data['split_midday'] + $data['split_evening'] !== 100) {
            throw ValidationException::withMessages(['split_morning' => 'Morning, midday and evening must add up to 100%.']);
        }
        $group = FeedingGroup::findOrFail($data['feeding_group_id']);

        DB::transaction(function () use ($data, $group, $request) {
            RationPlan::where('feeding_group_id', $group->id)->where('status', 'draft')->delete();
            $plan = RationPlan::create([
                'feeding_group_id' => $group->id, 'effective_from' => $data['effective_from'], 'split_morning' => $data['split_morning'], 'split_midday' => $data['split_midday'],
                'split_evening' => $data['split_evening'], 'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->name,
            ]);
            $plan->lines()->createMany(collect($data['lines'])->map(fn ($l) => ['feed_item_id' => $l['feed_item_id'], 'kg_per_head' => $l['kg_per_head']])->all());
        });
        Farm::log($request->user(), "Drafted a ration for {$group->name}", '/feeding');

        return back()->with('success', "Ration for {$group->name} saved. A veterinarian approves it before it is used.");
    }

    public function approve(Request $request, RationPlan $plan): RedirectResponse
    {
        abort_unless($request->user()->passes('approve_feeding_plan'), 403, 'Only an authorised veterinarian approves a feeding plan.');
        abort_unless($plan->status === 'draft', 422, 'This ration is already approved.');
        $plan->update(['status' => 'approved', 'approved_by' => $request->user()->name, 'approved_at' => now()]);
        Farm::log($request->user(), "Approved the ration for {$plan->group->name}", '/feeding');

        return back()->with('success', "Ration for {$plan->group->name} approved from {$plan->effective_from->format('j M')}.");
    }

    public function settings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canModule('settings'), 403);
        $time = ['required', 'date_format:H:i'];
        $data = $request->validate([
            'morning' => $time, 'midday' => [...$time, 'after:morning'], 'evening' => [...$time, 'after:midday'],
            'late' => ['required', 'integer', 'between:5,240'], 'window' => ['required', 'integer', 'between:30,720', 'gte:late'],
            'tolerance' => ['required', 'integer', 'between:1,50'],
        ]);
        Organization::whereKey($request->user()->org_id)->update([
            'meal_times' => json_encode(['morning' => $data['morning'], 'midday' => $data['midday'], 'evening' => $data['evening']]),
            'meal_late_minutes' => $data['late'], 'meal_window_minutes' => $data['window'], 'feed_tolerance_pct' => $data['tolerance'],
        ]);
        Farm::log($request->user(), 'Changed meal times', '/feeding');

        return back()->with('success', 'Meal times saved.');
    }
}
