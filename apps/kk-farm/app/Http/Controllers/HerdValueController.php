<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\MarketRate;
use App\Models\Organization;
use App\Support\Classification;
use App\Support\Farm;
use App\Support\HerdValue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** D-113: Herd Value: the herd priced by live weight like a market, against what it cost to buy and feed. */
class HerdValueController extends Controller
{
    public function index(Request $request): Response
    {
        $hv = new HerdValue;
        ['rows' => $rows, 'fallback' => $fallback] = $hv->build();
        $pkr = fn (?float $minor) => $minor === null ? null : round($minor / 100);
        $today = $hv->today();
        $general = $hv->rateOn($today, null);
        $weekAgo = $hv->rateOn($today->copy()->subDays(7), null);
        $purposes = Classification::PURPOSES;
        $trade = array_intersect_key($purposes, array_flip(HerdValue::TRADE_PURPOSES));
        // D-114: every batch, priced or not, so a batch can be given a purpose (and so be included) from this page.
        $all = Animal::active()->with('breed')->get();
        $allBatches = $all->groupBy(fn ($g) => HerdValue::batchOf($g))->map(fn ($gs, $b) => [
            'batch' => $b, 'goats' => $gs->count(), 'breed' => $gs->pluck('breed.name')->filter()->unique()->implode(', '),
            'buyRate' => ($br = $gs->pluck('purchase_rate_minor_per_kg')->filter()->unique())->count() === 1 ? $br->first() / 100 : null,
            'purposes' => $gs->countBy(fn ($g) => $purposes[$g->animal_purpose] ?? 'No purpose')->all(),
        ])->sortKeys()->values();

        $summary = function (Collection $rs) use ($pkr): array {
            $valued = $rs->whereNotNull('value');
            $costed = $rs->whereNotNull('value')->whereNotNull('buy');
            $kg = $rs->sum(fn ($r) => $r['weight'] ?? 0);
            $cost = $rs->sum(fn ($r) => ($r['buy'] ?? 0) + $r['feed']);

            return [
                'goats' => $rs->count(), 'weighed' => $rs->whereNotNull('weight')->count(), 'kg' => round($kg, 1),
                'avgKg' => $rs->whereNotNull('weight')->count() ? round($kg / $rs->whereNotNull('weight')->count(), 1) : null,
                'value' => $pkr($valued->sum('value')), 'valued' => $valued->count(),
                'buy' => $pkr($rs->sum(fn ($r) => $r['buy'] ?? 0)), 'bought' => $rs->whereNotNull('buy')->count(),
                'feed' => $pkr($rs->sum('feed')), 'cost' => $pkr($cost),
                'costPerKg' => $kg > 0 ? round($cost / $kg / 100) : null,
                // Gain only over goats with both a value and a purchase price, so a missing price never looks like profit.
                'gain' => $costed->count() ? $pkr($costed->sum(fn ($r) => $r['value'] - $r['buy'] - $r['feed'])) : null, 'gainGoats' => $costed->count(),
                'feedDay' => $pkr($rs->sum('feedDay')), 'feedKgDay' => round($rs->sum('feedKgDay'), 1),
                'periods' => collect(HerdValue::PERIODS)->map(fn ($days, $key) => [
                    'days' => $days, 'kg' => round($rs->sum(fn ($r) => $r['periods'][$key]['kg']), 1),
                    'value' => $pkr($rs->sum(fn ($r) => $r['periods'][$key]['value'] ?? 0)), 'feed' => $pkr($rs->sum(fn ($r) => $r['periods'][$key]['feed'])),
                    'net' => $pkr($rs->sum(fn ($r) => ($r['periods'][$key]['value'] ?? 0) - $r['periods'][$key]['feed'])),
                ])->all(),
            ];
        };

        return Inertia::render('HerdValue', [
            'rate' => ['now' => $general !== null ? $general / 100 : null, 'weekAgo' => $weekAgo !== null ? $weekAgo / 100 : null,
                'byPurpose' => collect($trade)->keys()->mapWithKeys(fn ($p) => [$p => ($r = $hv->rates()->where('purpose', $p)->last()) ? $r->rate_minor_per_kg / 100 : null])->filter()->all(),
                'history' => $hv->history(),
                'recent' => $hv->rates()->reverse()->take(12)->values()->map(fn (MarketRate $r) => ['on' => $r->rate_on->format('j M Y'), 'purpose' => $r->purpose, 'rate' => $r->rate_minor_per_kg / 100, 'by' => $r->recorded_by, 'note' => $r->note])],
            'total' => $summary($rows),
            'purposes' => $rows->groupBy(fn ($r) => $r['purpose'] ?? 'none')->map(fn ($rs, $p) => ['key' => $p, 'label' => $purposes[$p] ?? 'Purpose Not Set'] + $summary($rs))->values(),
            'batches' => $rows->groupBy('batch')->map(fn ($rs, $b) => ['batch' => $b, 'breed' => $rs->pluck('breed')->filter()->unique()->implode(', '),
                'buyRate' => ($br = $rs->pluck('buyRate')->filter()->unique())->count() === 1 ? $br->first() / 100 : null] + $summary($rs))->sortKeys()->values(),
            'goats' => $rows->map(fn ($r) => ['id' => $r['id'], 'number' => $r['number'], 'batch' => $r['batch'], 'purpose' => $purposes[$r['purpose']] ?? null, 'weight' => $r['weight'],
                'rate' => $r['rate'] !== null ? $r['rate'] / 100 : null, 'value' => $pkr($r['value']), 'buy' => $pkr($r['buy']), 'feed' => $pkr($r['feed']),
                'gain' => $r['value'] !== null && $r['buy'] !== null ? $pkr($r['value'] - $r['buy'] - $r['feed']) : null,
                'week' => $r['periods']['week']['value'] !== null ? $pkr($r['periods']['week']['value'] - $r['periods']['week']['feed']) : null])->values(),
            'feedPrice' => ['farm' => ($p = Organization::find($request->user()->org_id)?->feed_price_minor_per_kg) ? $p / 100 : null, 'used' => $fallback ? $fallback / 100 : null, 'priced' => $rows->where('priced', true)->count()],
            'purposeOptions' => $purposes, 'tradeOptions' => $trade, 'allBatches' => $allBatches,
            'excluded' => ['breeding' => $all->whereNotNull('animal_purpose')->whereNotIn('animal_purpose', HerdValue::TRADE_PURPOSES)->count(), 'none' => $all->whereNull('animal_purpose')->count()],
            'can' => ['manage' => $request->user()->passes('manage_market')],
        ]);
    }

    public function storeRate(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_market'), 403);
        $data = $request->validate([
            'rate_on' => ['required', 'date', 'before_or_equal:today'],
            'purpose' => ['nullable', Rule::in(array_keys(Classification::PURPOSES))],
            'rate' => ['required', 'numeric', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        MarketRate::create(['rate_on' => $data['rate_on'], 'purpose' => $data['purpose'] ?? null, 'rate_minor_per_kg' => (int) round($data['rate'] * 100), 'note' => $data['note'] ?? null, 'recorded_by' => $request->user()->name]);
        $for = isset($data['purpose']) ? Classification::PURPOSES[$data['purpose']] : 'all goats';
        Farm::log($request->user(), "Market rate {$data['rate']} PKR/KG for $for on {$data['rate_on']}", '/value');

        return back()->with('success', "Rate saved: PKR {$data['rate']} per KG live weight for $for.");
    }

    /** A batch's purchase rate per KG live weight (and optionally its purpose). Each goat's history keeps the change. */
    public function batch(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_market'), 403);
        $data = $request->validate([
            'batch' => ['required', 'string', 'max:40'],
            'rate' => ['nullable', 'numeric', 'min:1', 'max:100000', 'required_without:purpose'],
            'purpose' => ['nullable', Rule::in(array_keys(Classification::PURPOSES))],
        ]);
        $goats = Animal::active()->get()->filter(fn ($g) => HerdValue::batchOf($g) === $data['batch']); // every goat of the batch, trade or not
        abort_if($goats->isEmpty(), 404);
        DB::transaction(function () use ($goats, $data, $request) {
            foreach ($goats as $g) {
                $changes = [];
                $updates = [];
                if (isset($data['rate']) && (int) round($data['rate'] * 100) !== (int) $g->purchase_rate_minor_per_kg) {
                    $updates['purchase_rate_minor_per_kg'] = (int) round($data['rate'] * 100);
                    $changes[] = 'Purchase Rate: '.($g->purchase_rate_minor_per_kg ? ($g->purchase_rate_minor_per_kg / 100).' PKR/KG' : 'empty').' → '.$data['rate'].' PKR/KG';
                }
                if (! empty($data['purpose']) && $data['purpose'] !== $g->animal_purpose) {
                    $updates['animal_purpose'] = $data['purpose'];
                    $changes[] = 'Purpose: '.(Classification::PURPOSES[$g->animal_purpose] ?? 'empty').' → '.Classification::PURPOSES[$data['purpose']];
                }
                if ($updates) {
                    $g->update($updates);
                    Farm::event($g, 'edit', "Edited (batch {$data['batch']}): ".implode('; ', $changes), $request->user()->name);
                }
            }
        });
        Farm::log($request->user(), "Batch {$data['batch']}: purchase rate ".($data['rate'] ?? '-').' PKR/KG, purpose '.($data['purpose'] ?? '-'), '/value');

        return back()->with('success', "Batch {$data['batch']} saved for {$goats->count()} goats.");
    }

    public function feedPrice(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_market'), 403);
        $data = $request->validate(['price' => ['nullable', 'numeric', 'min:1', 'max:100000']]);
        Organization::whereKey($request->user()->org_id)->update(['feed_price_minor_per_kg' => isset($data['price']) ? (int) round($data['price'] * 100) : null]);
        Farm::log($request->user(), 'Feed price for Herd Value: '.($data['price'] ?? 'none').' PKR/KG', '/value');

        return back()->with('success', 'Feed price saved.');
    }
}
