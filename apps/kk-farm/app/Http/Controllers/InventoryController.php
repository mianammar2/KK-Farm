<?php

namespace App\Http\Controllers;

use App\Models\StoreItem;
use App\Models\StoreMove;
use App\Support\Farm;
use App\Support\Stores;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** D-178: Inventory: all three stores in one view, and the general store (consumables, spares, tools). */
class InventoryController extends Controller
{
    public function index(Request $request): Response
    {
        $qty = Stores::generalQty();
        // D-241 (RBAC §4): inventory is scoped by store: vet → medicine, feeding → feed, caretaker and cleaning → general store
        $stores = Stores::visibleTo($request->user());
        $general = in_array('store', $stores, true);

        return Inertia::render('Inventory', [
            'overview' => collect(Stores::overview())->whereIn('kind', $stores)->values(),
            'moves' => collect(Stores::recentMoves())->whereIn('kind', $stores)->values(),
            'stores' => $stores,
            'items' => ! $general ? [] : StoreItem::orderByDesc('active')->orderBy('name')->get()->map(fn (StoreItem $i) => [
                'id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'category' => $i->category, 'unit' => $i->unit, 'reorder' => $i->reorder_level, 'cost' => $i->cost_per_unit_minor / 100,
                'active' => $i->active, 'notes' => $i->notes, 'qty' => (float) ($qty[$i->id] ?? 0), 'status' => Stores::status((float) ($qty[$i->id] ?? 0), $i->reorder_level),
            ]),
            'categories' => StoreItem::CATEGORIES, 'units' => StoreItem::UNITS, 'moveTypes' => collect(StoreMove::TYPES)->except('purchase')->map(fn ($t) => ['label' => $t[0], 'direction' => $t[1]]),
            'can' => ['manage' => $request->user()->passes('manage_general_store')],
        ]);
    }

    private function itemData(Request $request, ?StoreItem $item = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9._-]+$/', function ($a, $v, $fail) use ($item) {
                if (StoreItem::whereRaw('upper(code) = ?', [$v])->when($item, fn ($q) => $q->whereKeyNot($item->id))->exists()) {
                    $fail("Another item already uses $v.");
                }
            }],
            'name' => ['required', 'string', 'max:80'],
            'category' => ['required', Rule::in(array_keys(StoreItem::CATEGORIES))],
            'unit' => ['required', Rule::in(array_keys(StoreItem::UNITS))],
            'reorder_level' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'notes' => ['nullable', 'string', 'max:200'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    public function storeItem(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_general_store'), 403);
        $data = $this->itemData($request);
        $data['opening'] = $request->validate(['opening' => ['nullable', 'numeric', 'min:0', 'max:10000000']])['opening'] ?? null;
        DB::transaction(function () use ($data, $request) {
            $item = StoreItem::create(['code' => $data['code'], 'name' => $data['name'], 'category' => $data['category'], 'unit' => $data['unit'], 'reorder_level' => $data['reorder_level'] ?? 0,
                'cost_per_unit_minor' => (int) round(($data['cost'] ?? 0) * 100), 'notes' => $data['notes'] ?? null]);
            if (($data['opening'] ?? 0) > 0) {
                StoreMove::create(['store_item_id' => $item->id, 'moved_on' => now()->toDateString(), 'type' => 'opening', 'qty' => $data['opening'], 'rate_minor' => $item->cost_per_unit_minor, 'recorded_by' => $request->user()->name]);
            }
        });
        Farm::log($request->user(), "Added store item {$data['name']}", '/inventory');

        return back()->with('success', "{$data['name']} added to the store.");
    }

    public function updateItem(Request $request, StoreItem $item): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_general_store'), 403);
        $data = $this->itemData($request, $item);
        $item->update(['code' => $data['code'], 'name' => $data['name'], 'category' => $data['category'], 'unit' => $data['unit'], 'reorder_level' => $data['reorder_level'] ?? 0,
            'cost_per_unit_minor' => (int) round(($data['cost'] ?? $item->cost_per_unit_minor / 100) * 100), 'notes' => $data['notes'] ?? null, 'active' => $data['active'] ?? $item->active]);

        return back()->with('success', "{$item->name} saved.");
    }

    /** A receipt (no purchase), an issue for use, or an adjustment after a count. Purchases come from Purchase Orders. */
    public function move(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_general_store'), 403);
        $data = $request->validate([
            'store_item_id' => ['required', 'integer', 'exists:store_items,id'],
            'type' => ['required', Rule::in(['opening', 'receipt', 'issue', 'adjustment'])],
            'direction' => ['nullable', Rule::in(['in', 'out'])],
            'qty' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'moved_on' => ['required', 'date', 'before_or_equal:today'],
            'issued_to' => ['nullable', 'string', 'max:80'], 'reference' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:200'],
        ], [], ['issued_to' => 'issued to']);
        $fixed = StoreMove::TYPES[$data['type']][1];
        if ($fixed === 0 && empty($data['direction'])) {
            throw ValidationException::withMessages(['direction' => 'Choose whether stock goes in or out.']);
        }
        $sign = $fixed !== 0 ? $fixed : ($data['direction'] === 'in' ? 1 : -1);
        $item = StoreItem::findOrFail($data['store_item_id']);
        DB::transaction(function () use ($data, $sign, $item, $request) {
            DB::statement('LOCK TABLE store_moves IN SHARE ROW EXCLUSIVE MODE');
            $onHand = (float) StoreMove::where('store_item_id', $item->id)->sum('qty');
            if ($sign < 0 && $data['qty'] > $onHand + 0.0001) {
                throw ValidationException::withMessages(['qty' => "Only {$onHand} ".(StoreItem::UNITS[$item->unit] ?? $item->unit)." of {$item->name} in store."]);
            }
            StoreMove::create(['store_item_id' => $item->id, 'moved_on' => $data['moved_on'], 'type' => $data['type'], 'qty' => $sign * $data['qty'], 'rate_minor' => $item->cost_per_unit_minor,
                'issued_to' => $data['issued_to'] ?? null, 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'recorded_by' => $request->user()->name]);
        });
        Farm::log($request->user(), StoreMove::TYPES[$data['type']][0].": {$data['qty']} {$item->name}", '/inventory');

        return back()->with('success', 'Store updated.');
    }
}
