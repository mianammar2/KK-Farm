<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\Medicine;
use App\Models\MedicineMovement;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** D-074: the medicine store (workbook "Medicine Stock" and "Medicine Movements"). Owner, manager and vet. */
class MedicineController extends Controller
{
    private const ATTRIBUTES = ['cost_per_unit' => 'cost per unit', 'reorder_level' => 'reorder level', 'opening_qty' => 'stock now', 'expiry_on' => 'expiry date', 'qty' => 'quantity'];

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_medicine_store'), 403);
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9._-]+$/', 'unique:medicines,code'],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(array_keys(Medicine::CATEGORIES))],
            'unit' => ['required', Rule::in(array_keys(Medicine::UNITS))],
            'reorder_level' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'storage' => ['nullable', 'string', 'max:80'],
            'supplier' => ['nullable', 'string', 'max:80'],
            'opening_qty' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'batch' => ['nullable', 'string', 'max:40'],
            'expiry_on' => ['nullable', 'date'],
        ], [], self::ATTRIBUTES);

        DB::transaction(function () use ($data, $request) {
            $medicine = Medicine::create([
                'code' => $data['code'], 'name' => $data['name'], 'category' => $data['category'], 'unit' => $data['unit'], 'reorder_level' => $data['reorder_level'] ?? 0,
                'cost_per_unit_minor' => (int) round(($data['cost_per_unit'] ?? 0) * 100), 'storage' => $data['storage'] ?? null, 'supplier' => $data['supplier'] ?? null,
            ]);
            if (($data['opening_qty'] ?? 0) > 0) {
                MedicineMovement::create(['medicine_id' => $medicine->id, 'moved_on' => now(), 'type' => 'opening', 'qty' => $data['opening_qty'], 'batch' => $data['batch'] ?? null, 'expiry_on' => $data['expiry_on'] ?? null, 'rate_minor' => $medicine->cost_per_unit_minor, 'recorded_by' => $request->user()->name]);
            }
        });
        Farm::log($request->user(), "Added medicine {$data['name']}", '/health');

        return back()->with('success', "{$data['name']} added to the medicine store.");
    }

    public function update(Request $request, Medicine $medicine): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_medicine_store'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'category' => ['required', Rule::in(array_keys(Medicine::CATEGORIES))],
            'reorder_level' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'cost_per_unit' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'storage' => ['nullable', 'string', 'max:80'], 'supplier' => ['nullable', 'string', 'max:80'], 'active' => ['boolean'],
        ], [], self::ATTRIBUTES);
        $medicine->update([
            'name' => $data['name'], 'category' => $data['category'], 'reorder_level' => $data['reorder_level'] ?? 0, 'cost_per_unit_minor' => (int) round(($data['cost_per_unit'] ?? 0) * 100),
            'storage' => $data['storage'] ?? null, 'supplier' => $data['supplier'] ?? null, 'active' => $data['active'] ?? $medicine->active,
        ]);
        Farm::log($request->user(), "Updated medicine {$medicine->name}", '/health');

        return back()->with('success', "{$medicine->name} saved.");
    }

    /** Receipts, disposals and adjustments. A purchase also records the Medicine expense. Patient issues come from treatments. */
    public function movement(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_medicine_store'), 403);
        $data = $request->validate([
            'medicine_id' => ['required', 'integer', 'exists:medicines,id'],
            'type' => ['required', Rule::in(['purchase', 'return', 'disposal', 'adjustment', 'other'])],
            'direction' => ['nullable', Rule::in(['in', 'out'])],
            'qty' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'batch' => ['nullable', 'string', 'max:40'],
            'expiry_on' => ['nullable', 'date'],
            'rate' => ['nullable', 'required_if:type,purchase', 'numeric', 'min:0', 'max:10000000'],
            'moved_on' => ['required', 'date', 'before_or_equal:today'],
            'party' => ['nullable', 'string', 'max:80'], 'reference' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:200'],
        ], [], self::ATTRIBUTES);
        $fixed = MedicineMovement::TYPES[$data['type']][1];
        if ($fixed === 0 && empty($data['direction'])) {
            throw ValidationException::withMessages(['direction' => 'Choose whether stock goes in or out.']);
        }
        $sign = $fixed !== 0 ? $fixed : ($data['direction'] === 'in' ? 1 : -1);
        if ($data['type'] === 'purchase' && empty($data['expiry_on'])) {
            throw ValidationException::withMessages(['expiry_on' => 'Enter the expiry date printed on the pack.']);
        }
        $medicine = Medicine::findOrFail($data['medicine_id']);
        $rate = isset($data['rate']) ? (int) round($data['rate'] * 100) : null;

        DB::transaction(function () use ($data, $sign, $medicine, $rate, $request) {
            $ledger = null;
            if ($data['type'] === 'purchase' && $rate > 0) {
                $ledger = LedgerEntry::create([
                    'direction' => 'out', 'category' => 'Medicine', 'amount' => round($data['qty'] * $rate / 100, 2), 'occurred_on' => $data['moved_on'],
                    'counterparty' => $data['party'] ?? null, 'description' => "{$data['qty']} {$medicine->unit} {$medicine->name}".(! empty($data['batch']) ? " · batch {$data['batch']}" : ''),
                    'recorded_by' => $request->user()->name,
                ]);
                $medicine->update(['cost_per_unit_minor' => $rate]);
            }
            MedicineMovement::create([
                'medicine_id' => $medicine->id, 'moved_on' => $data['moved_on'], 'type' => $data['type'], 'qty' => $sign * $data['qty'],
                'batch' => $data['batch'] ?? null, 'expiry_on' => $sign > 0 ? ($data['expiry_on'] ?? null) : null, 'rate_minor' => $rate,
                'party' => $data['party'] ?? null, 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
                'ledger_entry_id' => $ledger?->id, 'recorded_by' => $request->user()->name,
            ]);
        });
        Farm::log($request->user(), MedicineMovement::TYPES[$data['type']][0].": {$data['qty']} {$medicine->unit} {$medicine->name}", '/health');

        return back()->with('success', 'Medicine stock updated'.($data['type'] === 'purchase' ? ' and the expense recorded.' : '.'));
    }
}
