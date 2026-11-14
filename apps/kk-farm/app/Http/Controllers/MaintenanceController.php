<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Department;
use App\Models\LedgerEntry;
use App\Models\MaintenanceJob;
use App\Models\Shed;
use App\Models\Task;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-182: Maintenance & Assets. The asset register (owner and manager), breakdowns reported by anyone (the asset is
 * Under Repair and a task is raised), preventive services due from each asset's service interval, and job closure
 * with the work done and the cost, which is entered once as a Maintenance & Repairs expense in Accounting.
 */
class MaintenanceController extends Controller
{
    private function manager(Request $request): bool
    {
        return in_array($request->user()->role, ['ceo', 'manager'], true);
    }

    public function index(Request $request): Response
    {
        $assets = Asset::with('shed')->orderByRaw("case status when 'under_repair' then 0 when 'working' then 1 else 2 end")->orderBy('code')->get();
        $soon = now()->addDays(14)->startOfDay();

        return Inertia::render('Maintenance', [
            'assets' => $assets->map(fn (Asset $a) => [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'category' => $a->category, 'shed' => $a->shed?->name, 'shedId' => $a->shed_id, 'status' => $a->status,
                'purchased' => $a->purchased_on?->toDateString(), 'cost' => $a->cost_minor !== null ? $a->cost_minor / 100 : null, 'every' => $a->service_every_days,
                'next' => $a->next_service_on?->format('d/m/Y'), 'nextIso' => $a->next_service_on?->toDateString(), 'due' => $a->status !== 'retired' && $a->next_service_on && $a->next_service_on->lessThanOrEqualTo($soon),
                'overdue' => $a->status !== 'retired' && $a->next_service_on?->isPast(), 'notes' => $a->notes,
            ]),
            'jobs' => MaintenanceJob::with('asset')->where(fn ($q) => $q->where('status', '!=', 'done')->orWhere('done_on', '>=', now()->subDays(90)))
                ->orderByRaw("case status when 'open' then 0 when 'in_progress' then 1 else 2 end")->orderByDesc('requested_on')->get()->map(fn (MaintenanceJob $j) => [
                    'id' => $j->id, 'asset' => $j->asset?->name, 'assetCode' => $j->asset?->code, 'kind' => $j->kind, 'on' => $j->requested_on->format('d/m/Y'), 'problem' => $j->problem,
                    'priority' => $j->priority, 'status' => $j->status, 'by' => $j->requested_by, 'doneOn' => $j->done_on?->format('d/m/Y'), 'work' => $j->work_done,
                    'cost' => $j->cost_minor !== null ? $j->cost_minor / 100 : null, 'doneBy' => $j->done_by,
                ]),
            'spend' => [
                'month' => (int) round(MaintenanceJob::where('done_on', '>=', now()->startOfMonth())->sum('cost_minor') / 100),
                'year' => (int) round(MaintenanceJob::where('done_on', '>=', now()->startOfYear())->sum('cost_minor') / 100),
            ],
            'sheds' => Shed::orderBy('name')->get(['id', 'name']),
            'options' => ['categories' => Asset::CATEGORIES, 'status' => Asset::STATUS, 'kinds' => MaintenanceJob::KINDS, 'priority' => MaintenanceJob::PRIORITY],
            'canManage' => $this->manager($request),
        ]);
    }

    public function asset(Request $request, ?Asset $asset = null): RedirectResponse
    {
        abort_unless($this->manager($request), 403, 'The owner or manager keeps the asset register.');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('assets', 'code')->ignore($asset?->id)], 'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(array_keys(Asset::CATEGORIES))], 'shed_id' => ['nullable', 'exists:sheds,id'], 'purchased_on' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0'], 'status' => ['required', Rule::in(array_keys(Asset::STATUS))],
            'service_every_days' => ['nullable', 'integer', 'between:1,3650'], 'next_service_on' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $values = collect($data)->except('cost')->all() + ['cost_minor' => isset($data['cost']) ? (int) round($data['cost'] * 100) : null];
        if (empty($values['next_service_on']) && ! empty($values['service_every_days'])) {
            $values['next_service_on'] = now()->addDays($values['service_every_days'])->toDateString();
        }
        $asset ? $asset->update($values) : Asset::create($values);

        return back()->with('success', "{$data['name']} saved.");
    }

    public function report(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'asset_id' => ['required', 'exists:assets,id'], 'kind' => ['required', Rule::in(array_keys(MaintenanceJob::KINDS))],
            'problem' => ['required', 'string', 'max:1000'], 'priority' => ['required', Rule::in(array_keys(MaintenanceJob::PRIORITY))],
        ]);
        $asset = Asset::findOrFail($data['asset_id']);
        DB::transaction(function () use ($asset, $data, $request) {
            MaintenanceJob::create($data + ['requested_on' => now()->toDateString(), 'requested_by' => $request->user()->name]);
            if ($data['kind'] === 'repair') {
                $asset->update(['status' => 'under_repair']);
            }
            Task::create([
                'title' => mb_substr(MaintenanceJob::KINDS[$data['kind']].": {$asset->name}", 0, 190), 'type' => 'maintenance', 'priority' => $data['priority'], 'status' => 'open',
                'due_on' => now()->toDateString(), 'department_id' => Department::where('name', 'Management')->value('id'), 'notes' => mb_substr($data['problem'], 0, 500),
            ]);
        });
        Farm::log($request->user(), "Reported maintenance for {$asset->name}", '/maintenance?tab=jobs');

        return back()->with('success', 'Maintenance job opened.');
    }

    public function close(Request $request, MaintenanceJob $job): RedirectResponse
    {
        abort_unless($this->manager($request), 403);
        abort_unless($job->status !== 'done', 422, 'This job is already closed.');
        $data = $request->validate([
            'done_on' => ['required', 'date', 'before_or_equal:today'], 'work_done' => ['required', 'string', 'max:1000'], 'cost' => ['nullable', 'numeric', 'min:0'],
            'paid_to' => ['nullable', 'string', 'max:120'], 'paid' => ['boolean'],
        ]);
        DB::transaction(function () use ($job, $data, $request) {
            $asset = $job->asset;
            $cost = isset($data['cost']) && $data['cost'] > 0 ? (int) round($data['cost'] * 100) : null;
            $ledger = $cost ? LedgerEntry::create([
                'direction' => 'out', 'category' => 'Maintenance & Repairs', 'amount' => $cost / 100, 'occurred_on' => $data['done_on'], 'counterparty' => $data['paid_to'] ?? null,
                'description' => MaintenanceJob::KINDS[$job->kind].": {$asset->name} ({$asset->code})", 'recorded_by' => $request->user()->name,
                'payment_status' => ! empty($data['paid']) ? 'paid' : 'pending',
            ]) : null;
            $job->update(['status' => 'done', 'done_on' => $data['done_on'], 'work_done' => $data['work_done'], 'cost_minor' => $cost, 'done_by' => $request->user()->name, 'ledger_entry_id' => $ledger?->id]);
            $updates = $asset->status === 'under_repair' && ! MaintenanceJob::where('asset_id', $asset->id)->where('kind', 'repair')->where('status', '!=', 'done')->exists() ? ['status' => 'working'] : [];
            if ($job->kind === 'preventive' && $asset->service_every_days) {
                $updates['next_service_on'] = Carbon::parse($data['done_on'])->addDays($asset->service_every_days)->toDateString();
            }
            $asset->update($updates);
            Task::where('title', MaintenanceJob::KINDS[$job->kind].": {$asset->name}")->whereIn('status', Task::OPEN)
                ->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $request->user()->name]);
        });

        return back()->with('success', 'Job closed.');
    }
}
