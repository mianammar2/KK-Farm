<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\FeedItem;
use App\Models\HealthCase;
use App\Models\HealthRecord;
use App\Models\Mating;
use App\Models\Medicine;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Treatment;
use App\Models\User;
use App\Models\Weaning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * D-077: workbook "Action Center": the day's exceptions, each with a count, what to do, where to go and the first
 * few goats or items. Only modules the user can open are shown. Counts come from the same rules as the screens
 * they link to, so the number here always matches what the link shows.
 */
final class ActionCenter
{
    /** @return list<array{key: string, label: string, severity: string, count: int, action: string, url: string, items: list<array{text: string, sub: ?string, url: ?string}>}> */
    /** D-241 (RBAC §10): notification type => roles that receive it (always also needing the module and the page). */
    public const ROUTES = [
        // sick / on-watch goat, new health case
        'dose_overdue' => ['ceo', 'manager', 'vet'], 'open_cases' => ['ceo', 'manager', 'vet', 'caretaker'], 'concerns' => ['ceo', 'manager', 'vet', 'caretaker'],
        'withdrawal' => ['ceo', 'manager', 'vet'],
        // vaccination / checkup due
        'care_overdue' => ['manager', 'vet', 'caretaker'], 'checkups_due' => ['manager', 'vet', 'caretaker'],
        // medicine stock low / expiry
        'medicine' => ['ceo', 'manager', 'vet'],
        // pregnancy / kidding due, heat; breeding approval stays with the vet
        'check_overdue' => ['ceo', 'manager', 'vet', 'caretaker'], 'edd_passed' => ['ceo', 'manager', 'vet', 'caretaker'], 'kidding_imminent' => ['ceo', 'manager', 'vet', 'caretaker'],
        'kidding_soon' => ['ceo', 'manager', 'vet', 'caretaker'], 'weaning' => ['ceo', 'manager', 'vet', 'caretaker'], 'breeding_approval' => ['ceo', 'manager', 'vet'],
        // feeding due / ration changed; feed stock low
        'feeding_missed' => ['manager', 'caretaker', 'feeding'], 'feed_over' => ['manager', 'caretaker', 'feeding'], 'ration_low_cp' => ['manager', 'feeding'],
        'feed_low' => ['ceo', 'manager', 'feeding'],
        // milking due / anomaly
        'dry_off_due' => ['manager', 'vet', 'caretaker'],
        // records, housing, devices, money
        'removal_requests' => ['ceo', 'manager'], 'missing' => ['ceo', 'manager'], 'out_too_long' => ['ceo', 'manager', 'caretaker'],
        'sensor_alerts' => ['ceo', 'manager'], 'devices_offline' => ['ceo'], 'unpaid' => ['ceo', 'manager', 'finance'],
        // own / department tasks (the list itself is scoped)
        'tasks_overdue' => ['ceo', 'manager', 'vet', 'caretaker', 'feeding', 'cleaning', 'finance'],
        'death_approvals' => ['ceo', 'manager', 'vet', 'caretaker', 'feeding', 'cleaning', 'finance'], // whoever holds Approve a death (D-260)
    ];

    public static function items(User $user): array
    {
        $org = Tenant::org() ?? abort(404);
        $today = now()->startOfDay();
        $todayIso = $today->toDateString();
        $out = [];
        $add = function (string $module, string $key, string $label, string $severity, string $action, string $url, Collection $rows) use (&$out, $user) {
            // D-241 (RBAC §10): only the roles the notification is for, with the module, and only when the link opens for them
            [$path, $qs] = array_pad(explode('?', $url, 2), 2, '');
            parse_str($qs, $query);
            if ($rows->isEmpty() || ! $user->canModule($module) || ! in_array($user->role, self::ROUTES[$key] ?? ['ceo', 'manager'], true) || ! Pages::allows($user, $path, $query)) {
                return;
            }
            $out[] = ['key' => $key, 'label' => $label, 'severity' => $severity, 'count' => $rows->count(), 'action' => $action, 'url' => $url, 'items' => $rows->take(5)->values()->all()];
        };
        $goat = fn (?Animal $a, ?string $sub = null) => ['text' => $a?->farm_number ?? '?', 'sub' => $sub, 'url' => $a ? "/goats/{$a->id}" : null];

        // D-260: deaths the vet reported, waiting for the administrator's approval before the goat leaves the farm.
        if ($user->passes('approve_death')) {
            $deaths = \App\Models\DeathReport::with('animal')->where('status', 'pending')->orderBy('died_at')->get();
            $add('goats', 'death_approvals', 'Deaths to approve', 'critical', 'Read the vet\'s report, then approve the exit or send it back.', '/goats',
                $deaths->map(fn ($r) => $goat($r->animal, "{$r->cause} · {$r->died_at->format('d/m H:i')} · {$r->reported_by}")));
        }

        // D-231: goats that passed every breeding check and wait for an authorised approval.
        if ($user->passes('approve_breeding')) {
            $waiting = \App\Models\Task::with('animal')->where('title', 'like', Eligibility::TASK_PREFIX.'%')->whereIn('status', \App\Models\Task::OPEN)->orderBy('due_on')->get();
            $add('breeding', 'breeding_approval', 'Breeding approval pending', 'warning', 'Confirm the reproductive exam and genetics, then approve or reject.', '/breeding/eligibility',
                $waiting->map(fn ($t) => $goat($t->animal, 'qualified · due '.$t->due_on?->format('d/m'))));
        }

        // ---- Health ----
        // The latest dose of each active case; overdue when its next-dose date has passed.
        $dosesOverdue = Treatment::with(['animal', 'healthCase'])->whereIn('id', Treatment::whereHas('healthCase', fn ($q) => $q->whereIn('status', HealthCase::ACTIVE))
            ->groupBy('health_case_id')->selectRaw('max(id)'))->whereNotNull('next_dose_on')->where('next_dose_on', '<', $todayIso)->orderBy('next_dose_on')->get();
        $add('health', 'dose_overdue', 'Treatment dose overdue', 'critical', 'Give the dose or update the case with the vet.', '/health',
            $dosesOverdue->map(fn (Treatment $t) => $goat($t->animal, "{$t->product} · due {$t->next_dose_on->format('d/m')} · {$t->healthCase->label()}")));

        $cases = HealthCase::with('animal')->whereIn('status', HealthCase::ACTIVE)->orderByRaw("case severity when 'emergency' then 0 when 'high' then 1 when 'moderate' then 2 else 3 end")->get();
        $add('health', 'open_cases', 'Open or treated cases', $cases->whereIn('severity', ['emergency', 'high'])->isNotEmpty() ? 'critical' : 'warning', 'Review isolated and sick goats with the vet.', '/health',
            $cases->map(fn (HealthCase $c) => $goat($c->animal, "{$c->label()} · {$c->condition} · ".HealthCase::SEVERITIES[$c->severity])));

        $concerns = HealthRecord::with('animal')->where('status', 'open')->whereNotIn('id', HealthCase::whereNotNull('report_id')->select('report_id'))->orderByDesc('occurred_on')->get();
        $add('health', 'concerns', 'Reported concerns to review', 'warning', 'A vet opens a case or records "nothing found".', '/health',
            $concerns->map(fn (HealthRecord $r) => $goat($r->animal, "{$r->title} · {$r->occurred_on->format('d/m')} · {$r->recorded_by}")));

        // Preventive care: the latest record of each goat and care type, overdue.
        $care = HealthRecord::with('animal')->whereIn('id', DB::table('health_records')->whereIn('kind', ['vaccination', 'deworming', 'hoof_care'])->where(fn ($q) => $q->whereNull('care_status')->orWhere('care_status', '!=', 'cancelled'))
            ->selectRaw('distinct on (animal_id, kind) id')->orderBy('animal_id')->orderBy('kind')->orderByDesc('occurred_on')->orderByDesc('id'))
            ->where('next_due_on', '<', $todayIso)->whereHas('animal', fn ($q) => $q->active())->orderBy('next_due_on')->get();
        $add('health', 'care_overdue', 'Preventive care overdue', 'warning', 'Vaccinate or deworm and record it with the next due date.', '/health',
            $care->map(fn (HealthRecord $r) => $goat($r->animal, ucfirst(str_replace('_', ' ', $r->kind))." · {$r->title} · due {$r->next_due_on->format('d/m')}")));

        $withdrawal = Withdrawal::active();
        $wGoats = Animal::whereIn('id', $withdrawal->keys())->get()->keyBy('id');
        $add('health', 'withdrawal', 'Goats under withdrawal', 'info', 'Do not sell these goats or use their milk before the dates.', '/health',
            $withdrawal->map(fn ($w, $id) => $goat($wGoats[$id] ?? null, trim(($w['meat'] ? 'meat until '.Carbon::parse($w['meat'])->format('d/m') : '').($w['milk'] ? ' · milk until '.Carbon::parse($w['milk'])->format('d/m') : ''), ' ·')))->values());

        $qty = MedicineStock::quantities();
        $batches = MedicineStock::batches();
        $meds = Medicine::where('active', true)->orderBy('name')->get()
            ->map(fn (Medicine $m) => [$m, MedicineStock::status($qty[$m->id] ?? 0, $m->reorder_level, $batches[$m->id] ?? collect()), $qty[$m->id] ?? 0])
            ->filter(fn ($x) => in_array($x[1], ['negative', 'expired', 'reorder'], true));
        $add('health', 'medicine', 'Medicine expired or to reorder', $meds->contains(fn ($x) => $x[1] !== 'reorder') ? 'critical' : 'warning', 'Remove expired packs (record a disposal) and reorder.', '/health',
            $meds->map(fn ($x) => ['text' => $x[0]->name, 'sub' => MedicineStock::STATUS[$x[1]]." · {$x[2]} {$x[0]->unit}", 'url' => '/health']));

        // ---- Breeding ----
        $matings = Mating::with(['dam', 'checks'])->whereIn('status', ['open', 'pregnant', 'inconclusive'])->orderBy('expected_kidding_on')->get();
        $alerts = $matings->groupBy(fn (Mating $m) => $m->alert($org->kidding_alert_days) ?? 'none');
        $add('breeding', 'check_overdue', 'Pregnancy checks overdue', 'warning', 'Arrange the vet check and record the result.', '/breeding',
            collect($alerts['check_overdue'] ?? [])->merge($alerts['recheck_overdue'] ?? [])->map(fn (Mating $m) => $goat($m->dam, 'mated '.$m->mated_on->format('d/m').($m->check_due_on ? ' · check was due '.$m->check_due_on->format('d/m') : ''))));
        $add('breeding', 'edd_passed', 'Kidding overdue', 'critical', 'Check the doe and record the kidding or the outcome.', '/breeding',
            collect($alerts['edd_passed'] ?? [])->map(fn (Mating $m) => $goat($m->dam, 'expected '.$m->expected_kidding_on->format('d/m'))));
        // D-103: from 10 days before the expected kidding; urgent in the last 3 days.
        $left = fn (Mating $m) => (int) now()->startOfDay()->diffInDays($m->expected_kidding_on, false);
        $add('breeding', 'kidding_imminent', 'Kidding within 3 days', 'critical', 'Move her to the kidding pen, keep watch and have the vet on call.', '/breeding',
            collect($alerts['kidding_imminent'] ?? [])->map(fn (Mating $m) => $goat($m->dam, ($left($m) === 0 ? 'due today' : "in {$left($m)} day".($left($m) === 1 ? '' : 's')).' · day '.$m->mated_on->diffInDays(now()->startOfDay()).' of pregnancy')));
        $add('breeding', 'kidding_soon', 'Kidding in the next '.$org->kidding_alert_days.' days', 'warning', 'Prepare the kidding pen, bedding and colostrum; watch the doe.', '/breeding',
            collect($alerts['kidding_soon'] ?? [])->map(fn (Mating $m) => $goat($m->dam, "in {$left($m)} days · expected ".$m->expected_kidding_on->format('d/m'))));

        $weaningDue = Animal::active()->where('origin', 'born_on_farm')->where(fn ($q) => $q->whereNull('kid_stage')->orWhere('kid_stage', '!=', 'weaned'))
            ->whereNotNull('birth_date')->whereBetween('birth_date', [$today->copy()->subDays($org->weaning_age_days + 90)->toDateString(), $today->copy()->subDays($org->weaning_age_days)->toDateString()])
            ->whereNotIn('id', Weaning::where('status', 'completed')->select('kid_id'))
            ->whereNotIn('id', Weaning::where('status', 'postponed')->where('postponed_to', '>', $todayIso)->select('kid_id'))->orderBy('birth_date')->get();
        $add('breeding', 'weaning', 'Kids due for weaning', 'warning', 'Wean (weight, method, pen) or postpone with a reason.', '/breeding',
            $weaningDue->map(fn (Animal $a) => $goat($a, $a->birth_date->diffInDays($today).' days old')));

        // ---- Feeding ----
        // D-107: meals, missed feeding, store and shortage alerts only when the farm records feeding.
        if ($user->canModule('feeding') && $org->feed_tracking) {
            $board = Feed::board(Feed::now($org)->toDateString(), $org);
            $pens = \App\Models\Pen::with('shed')->whereIn('id', $board->keys())->get()->keyBy('id');
            $missed = $board->flatMap(fn ($meals, $penId) => collect($meals)->filter(fn ($m) => $m['status'] === 'missed')->map(fn ($m, $meal) => ['text' => $pens[$penId]?->fullName() ?? 'Pen', 'sub' => Feed::MEALS[$meal].' meal', 'url' => '/feeding']))->values();
            $add('feeding', 'feeding_missed', 'Feeding missed today', 'critical', 'Find out why and feed the pen.', '/feeding', $missed);
            // D-086: a pen given more than its goats need (e.g. goats moved out after it was fed).
            $over = $board->flatMap(fn ($meals, $penId) => collect($meals)->filter(fn ($m) => $m['balance']['state'] === 'over')
                ->map(fn ($m, $meal) => ['text' => $pens[$penId]?->fullName() ?? 'Pen', 'sub' => Feed::MEALS[$meal].': '.round($m['givenKg'], 2).' KG given, plan '.round($m['plannedKg'], 2).' KG (+'.round($m['balance']['diff'], 2).' KG)', 'url' => '/feeding']))->values();
            $add('feeding', 'feed_over', 'Pens fed more than they need', 'warning', 'Check the plan after goats moved, or record the leftover.', '/feeding', $over);
            $stock = Feed::stock();
            $low = FeedItem::where('active', true)->orderBy('name')->get()->filter(fn (FeedItem $i) => Feed::stockStatus($stock[$i->id] ?? 0, $i->reorder_level_kg) !== 'ok');
            // D-080: will the feed in store last? Need = every goat's intake % of body weight (or its fixed ration).
            $need = Feed::perGoat(Animal::active()->get(), $todayIso)->sum('kgDay');
            $inStore = FeedItem::where('active', true)->get()->sum(fn ($i) => max(0, $stock[$i->id] ?? 0));
            if ($need > 0) {
                $days = (int) floor($inStore / $need);
                if ($days < 7) {
                    $add('feeding', 'feed_short', $days === 0 ? 'Not enough feed for today' : "Feed lasts only {$days} day".($days === 1 ? '' : 's'), $days < 2 ? 'critical' : 'warning',
                        'Buy feed: the goats need '.round($need, 1).' KG a day ('.round($need * 7).' KG a week).', '/feeding',
                        collect([['text' => round($inStore, 1).' KG in store', 'sub' => round($need, 1).' KG needed a day · short '.round(max(0, $need * 7 - $inStore)).' KG for a week', 'url' => '/feeding']]));
                }
            }
            $add('feeding', 'feed_low', 'Feed low, out or below zero', $low->contains(fn ($i) => ($stock[$i->id] ?? 0) <= 0) ? 'critical' : 'warning', 'Check the store and record the purchase.', '/feeding',
                $low->map(fn (FeedItem $i) => ['text' => $i->name, 'sub' => round($stock[$i->id] ?? 0, 1).' KG · reorder at '.round($i->reorder_level_kg).' KG', 'url' => '/feeding'])->values());
        }

        // D-115: a ration in force whose mix CP is below the target for its group. Shown whether or not feeding is recorded.
        $add('feeding', 'ration_low_cp', 'Ration Crude Protein Below Target', 'warning', 'Add a higher-CP feed or change the shares in Feeding → Rations.', '/feeding?tab=rations',
            Feed::lowCpRations()->map(fn ($r) => ['text' => $r['group'], 'sub' => "Mix CP {$r['mix']}%, target {$r['target']}%".($r['standard'] ? " for {$r['standard']}" : '')." (short ".round($r['target'] - $r['mix'], 1)."%) · {$r['head']} goats", 'url' => '/feeding?tab=rations']));

        // D-117: does in milk past the farm's dry-off days.
        if ($org->dry_off_days) {
            $add('milking', 'dry_off_due', 'Does Due for Drying Off', 'info', 'In milk '.$org->dry_off_days.' days or more. Dry off in Milking, or keep milking.', '/milking',
                \App\Models\Lactation::open()->with('animal')->where('started_on', '<=', now()->subDays($org->dry_off_days)->toDateString())->orderBy('started_on')->get()
                    ->filter(fn ($l) => $l->animal?->status === 'active')->map(fn ($l) => $goat($l->animal, "Lactation {$l->number} · day {$l->daysInMilk()}"))->values());
        }

        // ---- Routine checkups (D-095) ----
        $due = \App\Support\Checkups::due();
        $add('health', 'checkups_due', 'Health checkups due', 'info', 'Every '.\App\Support\Checkups::interval().' days. Open Veterinary & Health → Checkups.', '/health?tab=checkups',
            $due->map(fn ($a) => ['text' => $a->farm_number, 'sub' => $a->pen?->fullName(), 'url' => '/health?tab=checkups'])->values());

        // ---- Wrong entries waiting for a decision (D-091) ----
        if ($user->passes('remove_goats')) {
            $asked = Animal::whereNotNull('removal_requested_at')->where('status', '!=', 'voided')->orderBy('removal_requested_at')->get();
            $add('goats', 'removal_requests', 'Goat records asked to be removed', 'warning', 'Open the goat and confirm or cancel the removal.', '/goats',
                $asked->map(fn ($a) => ['text' => $a->farm_number, 'sub' => "Asked by {$a->removal_requested_by}: {$a->removal_reason}", 'url' => "/goats/{$a->id}"])->values());
        }

        // ---- Sensors and open areas (D-087) ----
        if ($user->canModule('sensors')) {
            $alerts = \App\Models\SensorAlert::with(['animal', 'pen.shed'])->where('status', 'open')->orderByDesc('id')->get();
            $add('sensors', 'sensor_alerts', 'Sensor alerts', $alerts->contains('severity', 'critical') ? 'critical' : 'warning', 'Check the goat or the pen; acknowledge or resolve the alert.', '/sensors',
                $alerts->map(fn ($a) => ['text' => $a->title, 'sub' => \App\Models\SensorAlert::KINDS[$a->kind] ?? $a->kind, 'url' => $a->animal_id ? "/goats/{$a->animal_id}" : '/sensors'])->values());
            $quiet = \App\Models\Device::where('status', 'active')->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subHours(\App\Support\Sensors::DEFAULTS['offline_hours'])))->orderBy('code')->get();
            $add('sensors', 'devices_offline', 'Devices not reporting', 'warning', 'Check power and network at the device.', '/sensors',
                $quiet->map(fn ($d) => ['text' => "{$d->code} {$d->name}", 'sub' => 'Last seen '.($d->last_seen_at?->diffForHumans() ?? 'never'), 'url' => '/sensors'])->values());
        }
        if ($user->canModule('sheds')) {
            $long = \App\Support\Sensors::outNow()->filter(fn ($p) => $p->at->lt(now()->subHours(\App\Support\Sensors::DEFAULTS['out_hours_max'])));
            $add('sheds', 'out_too_long', 'Goats out in an open area too long', 'warning', 'Bring them in, or check the gate reader missed them coming back.', '/',
                $long->map(fn ($p) => ['text' => $p->animal->farm_number, 'sub' => 'Out in '.($p->area?->fullName() ?? 'open area').' since '.$p->at->diffForHumans(), 'url' => "/goats/{$p->animal_id}"])->values());
        }

        // ---- Herd data ----
        $goats = Animal::with('pen')->active()->orderBy('farm_number')->get();
        $tags = HerdStats::tagsFor($goats);
        $add('goats', 'missing', 'Goats with missing or wrong data', 'warning', 'Put the goat in a pen, weigh it, or correct its birth date, so feed can be planned.', '/goats?show=missing',
            $goats->filter(fn (Animal $a) => in_array('missing', $tags[$a->id], true))->map(fn (Animal $a) => $goat($a, implode(' · ', array_map(fn ($k) => HerdStats::LABELS[$k], array_intersect(['no_pen', 'no_weight', 'age_conflict'], $tags[$a->id]))))));

        // ---- Money ----
        $unpaid = \App\Models\LedgerEntry::where('direction', 'out')->whereIn('payment_status', ['pending', 'partial'])->whereNull('reverses_id')->whereDoesntHave('reversal')->orderBy('occurred_on')->get();
        $add('finance', 'unpaid', 'Expenses not fully paid', 'info', 'Pay the vendor and record it.', '/finance',
            $unpaid->map(fn ($e) => ['text' => $e->counterparty ?? $e->category, 'sub' => $e->category.' · PKR '.number_format((float) $e->amount).' · '.$e->occurred_on->format('d/m').($e->payment_status === 'partial' ? ' · partly paid' : ''), 'url' => '/finance']));

        // ---- Tasks ----
        $tasks = Task::visibleTo($user)->whereIn('status', Task::OPEN)->where('due_on', '<', $todayIso)->orderBy('due_on')->get(); // D-241: own and department tasks only
        $add('tasks', 'tasks_overdue', 'Tasks overdue', 'warning', 'Finish or reassign the task.', '/tasks',
            $tasks->map(fn (Task $t) => ['text' => $t->title, 'sub' => 'due '.$t->due_on->format('d/m'), 'url' => '/tasks']));

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($out, fn ($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);

        return $out;
    }
}
