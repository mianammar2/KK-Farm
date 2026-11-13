<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\SaleOrder;
use App\Models\Task;
use App\Support\ActionCenter;
use App\Support\Pages;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-241 (RBAC §6): each role's own home page. The Executive Dashboard is the owner's and manager's; everyone else
 * lands here and sees only their work: their tasks, the alerts routed to them, a few numbers and their usual pages.
 */
class HomeController extends Controller
{
    /** role => [headline alert keys, usual pages [href, label]] */
    private const ROLE = [
        'vet' => [['open_cases', 'concerns', 'care_overdue', 'checkups_due', 'check_overdue', 'medicine', 'breeding_approval'], [
            ['/health?tab=cases', 'Medical Cases'], ['/disease?tab=triage', 'Triage Reported Symptoms'], ['/health?tab=checkups', 'Daily Health & Checkups'],
            ['/health?tab=calendar', 'Vaccination & Deworming Due'], ['/breeding/eligibility', 'Breeding Eligibility & Approval'], ['/health?tab=store', 'Medicines & Vaccines']]],
        'caretaker' => [['tasks_overdue', 'open_cases', 'checkups_due', 'kidding_imminent', 'kidding_soon', 'dry_off_due', 'feeding_missed'], [
            ['/worker', 'Fieldwork: Choose a Job'], ['/tasks?tab=mine', 'My Tasks'], ['/weights', 'Record Weights'], ['/milking', 'Log Milk'],
            ['/breeding?tab=calendar', 'Kidding Watch'], ['/health?tab=reports', 'Report a Sick Goat']]],
        'feeding' => [['tasks_overdue', 'feeding_missed', 'feed_over', 'feed_low', 'ration_low_cp'], [
            ['/feeding?tab=now', 'Daily Feeding'], ['/feeding?tab=rations', 'Feeding Plans & Rations'], ['/feeding?tab=store', 'Feed Store'],
            ['/fodder?tab=crops', 'Fodder'], ['/tasks?tab=mine', 'My Tasks']]],
        'cleaning' => [['tasks_overdue'], [
            ['/tasks?tab=mine', 'My Tasks'], ['/housing', 'Pen Status & Cleaning'], ['/environment?tab=sheds', 'Shed Rounds'],
            ['/inventory?tab=general', 'Cleaning Supplies'], ['/maintenance?tab=jobs', 'Report a Fault']]],
        'finance' => [['tasks_overdue', 'unpaid'], [
            ['/finance', 'Income & Farm Expenses'], ['/sales', 'Sales'], ['/sales/orders?tab=done', 'Invoices & Collections'], ['/purchase', 'Purchase Orders'],
            ['/value', 'Herd Value'], ['/finance/books?tab=pl', 'Profit & Loss']]],
    ];

    public function __invoke(Request $request): Response
    {
        $u = $request->user();
        [$keys, $links] = self::ROLE[$u->role] ?? [[], []];
        $alerts = collect(ActionCenter::items($u));
        $tasks = Task::visibleTo($u)->whereIn('status', Task::OPEN)->whereDate('due_on', '<=', now()->toDateString())->orderBy('due_on')->limit(20)->get();
        $numbers = $alerts->filter(fn ($a) => in_array($a['key'], $keys, true))->map(fn ($a) => ['label' => $a['label'], 'value' => $a['count'], 'url' => $a['url'], 'severity' => $a['severity']])->values();
        if ($u->role === 'finance') {
            $month = now()->startOfMonth()->toDateString();
            $sum = fn (string $dir) => (float) LedgerEntry::where('direction', $dir)->where('occurred_on', '>=', $month)->whereDoesntHave('reversal')->sum('amount');
            $numbers = collect([
                ['label' => 'Income this month', 'value' => 'PKR '.number_format($sum('in')), 'url' => '/finance', 'severity' => 'info'],
                ['label' => 'Spending this month', 'value' => 'PKR '.number_format($sum('out')), 'url' => '/finance', 'severity' => 'info'],
                ['label' => 'Sale orders open', 'value' => SaleOrder::whereIn('status', ['quote', 'reserved'])->count(), 'url' => '/sales/orders', 'severity' => 'info'],
            ])->merge($numbers);
        }

        return Inertia::render('Home', [
            'role' => $u->roleLabel(),
            'numbers' => $numbers->values(),
            'tasks' => $tasks->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'due' => $t->due_on?->format('j M'), 'overdue' => $t->due_on?->lt(now()->startOfDay()), 'priority' => $t->priority])->values(),
            'alerts' => $alerts->map(fn ($a) => ['key' => $a['key'], 'label' => $a['label'], 'count' => $a['count'], 'url' => $a['url'], 'severity' => $a['severity'], 'action' => $a['action']])->values(),
            'links' => collect($links)->filter(function ($l) use ($u) {
                [$path, $qs] = array_pad(explode('?', $l[0], 2), 2, '');
                parse_str($qs, $q);

                return Pages::allows($u, $path, $q);
            })->map(fn ($l) => ['href' => $l[0], 'label' => $l[1]])->values(),
        ]);
    }
}
