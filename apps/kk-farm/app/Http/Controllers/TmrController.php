<?php

namespace App\Http\Controllers;

use App\Models\FeedItem;
use App\Models\TmrFormula;
use App\Support\Farm;
use App\Support\Tmr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** D-177: TMR formulas (Feeding → Configuration) and mixing batches (Feeding → Operations). */
class TmrController extends Controller
{
    private function rules(?TmrFormula $formula = null): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9._-]+$/', 'unique:feed_items,code'.($formula ? ','.$formula->feed_item_id : '')],
            'notes' => ['nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
            'reorder_level_kg' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'lines' => ['required', 'array', 'min:2', 'max:20'],
            'lines.*.feed_item_id' => ['required', 'integer', 'exists:feed_items,id'],
            'lines.*.pct' => ['required', 'numeric', 'gt:0', 'max:100'],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('plan_rations'), 403);
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate($this->rules(), [], ['lines.*.pct' => 'share', 'lines.*.feed_item_id' => 'feed']);
        $recipe = Tmr::checkLines($data['lines']);
        $a = Tmr::analyse($recipe);

        $formula = DB::transaction(function () use ($data, $a, $request) {
            $item = FeedItem::create(['code' => $data['code'], 'name' => $data['name'], 'category' => 'tmr', 'cost_per_kg_minor' => $a['costMinor'], 'cp_pct' => $a['cp'], 'dm_pct' => $a['dm'], 'reorder_level_kg' => $data['reorder_level_kg'] ?? 0, 'notes' => 'TMR formula']);
            $formula = TmrFormula::create(['feed_item_id' => $item->id, 'name' => $data['name'], 'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->name]);
            foreach ($data['lines'] as $l) {
                $formula->lines()->create(['feed_item_id' => $l['feed_item_id'], 'pct' => $l['pct']]);
            }

            return $formula;
        });
        Farm::log($request->user(), "Added TMR formula {$formula->name}", '/feeding?tab=tmr');

        return back()->with('success', "{$formula->name} added: CP ".($a['cp'] ?? '?').' %, PKR '.number_format($a['costMinor'] / 100, 2).' per KG.');
    }

    public function update(Request $request, TmrFormula $formula): RedirectResponse
    {
        abort_unless($request->user()->passes('plan_rations'), 403);
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate($this->rules($formula), [], ['lines.*.pct' => 'share', 'lines.*.feed_item_id' => 'feed']);
        $recipe = Tmr::checkLines($data['lines'], $formula->feed_item_id);
        $a = Tmr::analyse($recipe);

        DB::transaction(function () use ($formula, $data, $a) {
            $formula->item->update(['code' => $data['code'], 'name' => $data['name'], 'cp_pct' => $a['cp'], 'dm_pct' => $a['dm'], 'reorder_level_kg' => $data['reorder_level_kg'] ?? $formula->item->reorder_level_kg, 'active' => $data['active'] ?? $formula->active]);
            $formula->update(['name' => $data['name'], 'notes' => $data['notes'] ?? null, 'active' => $data['active'] ?? $formula->active]);
            $formula->lines()->delete(); // the recipe is configuration; batches already mixed keep their own stock movements
            foreach ($data['lines'] as $l) {
                $formula->lines()->create(['feed_item_id' => $l['feed_item_id'], 'pct' => $l['pct']]);
            }
        });
        Farm::log($request->user(), "Updated TMR formula {$formula->name}", '/feeding?tab=tmr');

        return back()->with('success', "{$formula->name} saved.");
    }

    public function mix(Request $request, TmrFormula $formula): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_feed_store'), 403);
        $data = $request->validate([
            'total_kg' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'mixed_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:300'],
        ], [], ['total_kg' => 'batch size']);
        $batch = Tmr::mix($formula, (float) $data['total_kg'], $data['mixed_on'], $request->user(), $data['notes'] ?? null);
        Farm::log($request->user(), "Mixed {$batch->total_kg} KG {$formula->name} ({$batch->batch_no})", '/feeding?tab=tmr');

        return back()->with('success', "Batch {$batch->batch_no}: {$batch->total_kg} KG {$formula->name} mixed and in store. Ingredients taken out of stock.");
    }
}
