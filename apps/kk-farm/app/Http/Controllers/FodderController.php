<?php

namespace App\Http\Controllers;

use App\Models\FodderCrop;
use App\Models\FodderField;
use App\Models\FodderInput;
use App\Models\FodderLot;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Support\Farm;
use App\Support\Fodder;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** D-202: App 22 Fodder & Pasture and journey M Fodder-to-TMR (V3 flows 22 and 36). */
class FodderController extends Controller
{
    private function manager(Request $request): bool
    {
        return in_array($request->user()->role, ['ceo', 'manager'], true);
    }

    public function index(Request $request): Response
    {
        $enabled = Fodder::enabled();
        $crops = $enabled ? FodderCrop::with(['field', 'inputs', 'lots'])->orderByRaw("status = 'growing' desc")->orderByDesc('sown_on')->limit(100)->get() : collect();
        $lots = $enabled ? FodderLot::with(['crop.field', 'item'])->orderByRaw("case status when 'quarantine' then 0 else 1 end")->orderByDesc('harvested_on')->orderByDesc('id')->limit(150)->get() : collect();

        return Inertia::render('Fodder', [
            'enabled' => $enabled, 'isOwner' => $request->user()->role === 'ceo', 'canManage' => $this->manager($request),
            'canRelease' => in_array($request->user()->role, ['ceo', 'manager', 'vet'], true), 'me' => $request->user()->name,
            'fields' => $enabled ? FodderField::orderBy('name')->get()->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'acres' => $f->area_acres, 'location' => $f->location, 'soil' => $f->soil, 'irrigation' => $f->irrigation, 'active' => $f->active]) : [],
            'crops' => $crops->map(fn (FodderCrop $c) => [
                'id' => $c->id, 'label' => $c->label(), 'crop' => $c->crop, 'field' => $c->field?->name, 'acres' => $c->area_acres, 'sown' => $c->sown_on->format('d/m/Y'), 'expected' => $c->expected_harvest_on?->format('d/m/Y'),
                'expectedKg' => $c->expected_yield_kg, 'status' => $c->status, 'cost' => (int) $c->inputs->sum('cost_minor'), 'harvestedKg' => (float) $c->lots->sum('fresh_kg'),
                'safeFrom' => $c->inputs->where('kind', 'chemical')->max('safe_from')?->format('d/m/Y'),
                'phiOpen' => (bool) $c->inputs->where('kind', 'chemical')->first(fn ($i) => $i->safe_from && $i->safe_from->isFuture()),
                'inputs' => $c->inputs->map(fn ($i) => ['id' => $i->id, 'kind' => $i->kind, 'on' => $i->applied_on->format('d/m'), 'product' => $i->product, 'qty' => $i->qty, 'unit' => $i->unit, 'cost' => $i->cost_minor, 'safeFrom' => $i->safe_from?->format('d/m/Y')])->values(),
            ]),
            'lots' => $lots->map(fn (FodderLot $l) => [
                'id' => $l->id, 'no' => $l->label(), 'crop' => $l->crop?->label(), 'on' => $l->harvested_on->format('d/m/Y'), 'form' => $l->form, 'kg' => $l->fresh_kg, 'final' => $l->final_cut,
                'dm' => $l->dm_pct, 'dmMethod' => $l->dm_method, 'mould' => $l->mould, 'smellOk' => $l->smell_ok, 'nitrate' => $l->nitrate, 'aflatoxin' => $l->aflatoxin_ppb, 'cp' => $l->cp_pct, 'lab' => $l->test_lab,
                'status' => $l->status, 'harvestedBy' => $l->harvested_by, 'decidedBy' => $l->decided_by, 'note' => $l->decision_note, 'blockers' => $l->status === 'quarantine' ? Fodder::releaseBlockers($l) : [],
                'cost' => $l->cost_minor, 'perKg' => $l->cost_per_kg_minor, 'perKgDm' => $l->cost_per_kg_dm_minor, 'item' => $l->item?->name,
                'formulas' => $l->feed_item_id ? Fodder::formulasUsing($l->feed_item_id) : [],
            ]),
            'kpi' => $enabled ? Fodder::kpis() : null, 'aflatoxinLimit' => (int) (Tenant::org()?->aflatoxin_limit_ppb ?? 20),
            'options' => ['crops' => FodderCrop::CROPS, 'inputs' => FodderInput::KINDS, 'forms' => FodderLot::FORMS, 'dmMethods' => FodderLot::DM_METHODS, 'irrigation' => FodderField::IRRIGATION],
        ]);
    }

    public function toggle(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'ceo', 403, 'The owner turns Fodder & Pasture on or off.');
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'aflatoxin_limit_ppb' => ['nullable', 'integer', 'between:1,500']]);
        Organization::whereKey(Tenant::current())->update(['fodder_enabled' => $data['enabled']] + (isset($data['aflatoxin_limit_ppb']) ? ['aflatoxin_limit_ppb' => $data['aflatoxin_limit_ppb']] : []));
        Tenant::flush();

        return back()->with('success', $data['enabled'] ? 'Fodder & Pasture is on.' : 'Fodder & Pasture is off.');
    }

    public function field(Request $request, ?FodderField $field = null): RedirectResponse
    {
        abort_unless($this->manager($request), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'area_acres' => ['required', 'numeric', 'gt:0', 'max:100000'], 'location' => ['nullable', 'string', 'max:160'],
            'soil' => ['nullable', 'string', 'max:60'], 'irrigation' => ['nullable', Rule::in(array_keys(FodderField::IRRIGATION))], 'active' => ['boolean']]);
        $field ? $field->update($data) : FodderField::create($data);

        return back()->with('success', "{$data['name']} saved.");
    }

    public function crop(Request $request): RedirectResponse
    {
        abort_unless($this->manager($request) || $request->user()->role === 'feeding', 403);
        $data = $request->validate(['field_id' => ['required', 'exists:fodder_fields,id'], 'crop' => ['required', Rule::in(array_keys(FodderCrop::CROPS))], 'variety' => ['nullable', 'string', 'max:60'],
            'area_acres' => ['required', 'numeric', 'gt:0'], 'sown_on' => ['required', 'date', 'before_or_equal:today'], 'expected_harvest_on' => ['nullable', 'date', 'after:sown_on'],
            'expected_yield_kg' => ['required', 'integer', 'min:1'], 'notes' => ['nullable', 'string', 'max:500']], [], ['expected_yield_kg' => 'expected yield']);
        $field = FodderField::findOrFail($data['field_id']);
        if ($data['area_acres'] > $field->area_acres) {
            throw ValidationException::withMessages(['area_acres' => "{$field->name} is {$field->area_acres} acres."]);
        }
        FodderCrop::create($data + ['created_by' => $request->user()->name]);

        return back()->with('success', 'Crop recorded.');
    }

    /** An input with its cost (expensed once as Fodder Production); a chemical needs its pre-harvest interval. */
    public function input(Request $request, FodderCrop $crop): RedirectResponse
    {
        abort_unless($this->manager($request) || $request->user()->role === 'feeding', 403);
        abort_if($crop->status === 'closed', 422, 'This crop is closed.');
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(FodderInput::KINDS))], 'applied_on' => ['required', 'date', 'before_or_equal:today'], 'product' => ['nullable', 'required_if:kind,chemical', 'string', 'max:120'],
            'qty' => ['nullable', 'numeric', 'min:0'], 'unit' => ['nullable', 'string', 'max:12'], 'cost' => ['nullable', 'numeric', 'min:0'],
            'phi_days' => ['nullable', 'required_if:kind,chemical', 'integer', 'between:0,365']], [], ['phi_days' => 'pre-harvest interval (days)']);
        $user = $request->user();
        DB::transaction(function () use ($crop, $data, $user) {
            $minor = ! empty($data['cost']) ? (int) round($data['cost'] * 100) : null;
            $ledger = $minor ? LedgerEntry::create(['direction' => 'out', 'category' => 'Fodder Production', 'amount' => $minor / 100, 'occurred_on' => $data['applied_on'],
                'description' => FodderInput::KINDS[$data['kind']].($data['product'] ?? null ? " {$data['product']}" : '')." · {$crop->label()}", 'recorded_by' => $user->name, 'payment_status' => 'pending']) : null;
            FodderInput::create(collect($data)->except('cost')->all() + ['crop_id' => $crop->id, 'cost_minor' => $minor, 'ledger_entry_id' => $ledger?->id, 'recorded_by' => $user->name,
                'safe_from' => isset($data['phi_days']) ? Carbon::parse($data['applied_on'])->addDays($data['phi_days'])->toDateString() : null]);
        });

        return back()->with('success', 'Input recorded.');
    }

    /** Harvest a lot into quarantine. Refused inside a chemical's pre-harvest interval. */
    public function harvest(Request $request, FodderCrop $crop): RedirectResponse
    {
        abort_unless($this->manager($request) || $request->user()->role === 'feeding', 403);
        abort_if($crop->status === 'closed', 422, 'This crop is closed.');
        $data = $request->validate(['harvested_on' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.$crop->sown_on->toDateString()], 'form' => ['required', Rule::in(array_keys(FodderLot::FORMS))],
            'fresh_kg' => ['required', 'numeric', 'gt:0', 'max:10000000'], 'final_cut' => ['boolean']], [], ['fresh_kg' => 'weight']);
        if ($p = Fodder::phiBlocks($crop, $data['harvested_on'])) {
            throw ValidationException::withMessages(['harvested_on' => 'Chemical pre-harvest interval not over: '.implode(', ', $p).'. Wait before cutting for feed.']);
        }
        $lot = DB::transaction(function () use ($crop, $data, $request) {
            DB::statement('LOCK TABLE fodder_lots IN SHARE ROW EXCLUSIVE MODE');

            return FodderLot::create($data + ['crop_id' => $crop->id, 'lot_no' => (int) FodderLot::max('lot_no') + 1, 'final_cut' => (bool) ($data['final_cut'] ?? false), 'harvested_by' => $request->user()->name]);
        });
        Farm::log($request->user(), "Harvested fodder lot {$lot->label()}", '/fodder?tab=lots');

        return back()->with('success', "{$lot->label()} in quarantine: test DM and quality before release.");
    }

    /** Quality tests and measured dry matter. */
    public function test(Request $request, FodderLot $lot): RedirectResponse
    {
        abort_unless($lot->status === 'quarantine', 422, 'This lot was already decided.');
        $data = $request->validate(['dm_pct' => ['required', 'numeric', 'between:5,95'], 'dm_method' => ['required', Rule::in(array_keys(FodderLot::DM_METHODS))],
            'mould' => ['required', 'boolean'], 'smell_ok' => ['required', 'boolean'], 'nitrate' => ['required', Rule::in(['untested', 'low', 'medium', 'high'])],
            'aflatoxin_ppb' => ['nullable', 'numeric', 'min:0', 'max:10000'], 'cp_pct' => ['nullable', 'numeric', 'between:1,40'], 'test_lab' => ['nullable', 'string', 'max:120']],
            [], ['dm_pct' => 'dry matter %', 'dm_method' => 'how dry matter was measured']);
        $lot->update($data + ['tested_by' => $request->user()->name]);

        return back()->with('success', ($b = Fodder::releaseBlockers($lot->fresh())) ? 'Tests saved. Still blocking: '.implode('; ', $b).'.' : 'Tests saved: ready for release.');
    }

    public function decide(Request $request, FodderLot $lot): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['release', 'reject'])], 'note' => ['nullable', 'required_if:decision,reject', 'string', 'max:500']]);
        $data['decision'] === 'release' ? Fodder::release($lot, $request->user(), $data['note'] ?? null) : Fodder::reject($lot, $request->user(), $data['note']);

        return back()->with('success', $data['decision'] === 'release' ? "{$lot->label()} released into feed stock." : "{$lot->label()} rejected.");
    }
}
