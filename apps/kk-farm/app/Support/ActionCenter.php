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
    public static function items(User $user): array
    {
        $org = Organization::findOrFail(Tenant::current());
        $today = now()->startOfDay();
        $todayIso = $today->toDateString();
        $out = [];
        $add = function (string $module, string $key, string $label, string $severity, string $action, string $url, Collection $rows) use (&$out, $user) {
            if ($rows->isEmpty() || ! $user->canModule($module)) {
                return;
            }
            $out[] = ['key' => $key, 'label' => $label, 'severity' => $severity, 'count' => $rows->count(), 'action' => $action, 'url' => $url, 'items' => $rows->take(5)->values()->all()];
        };
        $goat = fn (?Animal $a, ?string $sub = null) => ['text' => $a?->farm_number ?? '?', 'sub' => $sub, 'url' => $a ? "/goats/{$a->id}" : null];

        // ---- Health ----
        // The latest dose of each active case; overdue when its next-dose date has passed.
        $dosesOverdue = Treatment::with(['animal', 'healthCase'])->whereIn('id', Treatment::whereHas('healthCase', fn ($q) => $q->whereIn('status', HealthCase::ACTIVE))
            ->groupBy('health_case_id')->selectRaw('max(id)'))->whereNotNull('next_dose_on')->where('next_dose_on', '<', $todayIso)->orderBy('next_dose_on')->get();
        $add('health', 'dose_overdue', 'Treatment dose overdue', 'critical', 'Give the dose or update the case with the vet.', '/health',
            $dosesOverdue->map(fn (Treatment $t) => $goat($t->animal, "{$t->product} · due {$t->next_dose_on->format('j M')} · {$t->healthCase->label()}")));

        $cases = HealthCase::with('animal')->whereIn('status', HealthCase::ACTIVE)->orderByRaw("case severity when 'emergency' then 0 when 'high' then 1 when 'moderate' then 2 else 3 end")->get();
        $add('health', 'open_cases', 'Open or treated cases', $cases->whereIn('severity', ['emergency', 'high'])->isNotEmpty() ? 'critical' : 'warning', 'Review isolated and sick goats with the vet.', '/health',
            $cases->map(fn (HealthCase $c) => $goat($c->animal, "{$c->label()} · {$c->condition} · ".HealthCase::SEVERITIES[$c->severity])));

        $concerns = HealthRecord::with('animal')->where('status', 'open')->whereNotIn('id', HealthCase::whereNotNull('report_id')->select('report_id'))->orderByDesc('occurred_on')->get();
        $add('health', 'concerns', 'Reported concerns to review', 'warning', 'A vet opens a case or records "nothing found".', '/health',
            $concerns->map(fn (HealthRecord $r) => $goat($r->animal, "{$r->title} · {$r->occurred_on->format('j M')} · {$r->recorded_by}")));

        // Preventive care: the latest record of each goat and care type, overdue.
        $care = HealthRecord::with('animal')->whereIn('id', DB::table('health_records')->whereIn('kind', ['vaccination', 'deworming'])
            ->selectRaw('distinct on (animal_id, kind) id')->orderBy('animal_id')->orderBy('kind')->orderByDesc('occurred_on')->orderByDesc('id'))
            ->where('next_due_on', '<', $todayIso)->whereHas('animal', fn ($q) => $q->active())->orderBy('next_due_on')->get();
        $add('health', 'care_overdue', 'Preventive care overdue', 'warning', 'Vaccinate or deworm and record it with the next due date.', '/health',
            $care->map(fn (HealthRecord $r) => $goat($r->animal, ucfirst($r->kind)." · {$r->title} · due {$r->next_due_on->format('j M')}")));

        $withdrawal = Withdrawal::active();
        $wGoats = Animal::whereIn('id', $withdrawal->keys())->get()->keyBy('id');
        $add('health', 'withdrawal', 'Goats under withdrawal', 'info', 'Do not sell these goats or use their milk before the dates.', '/health',
            $withdrawal->map(fn ($w, $id) => $goat($wGoats[$id] ?? null, trim(($w['meat'] ? 'meat until '.Carbon::parse($w['meat'])->format('j M') : '').($w['milk'] ? ' · milk until '.Carbon::parse($w['milk'])->format('j M') : ''), ' ·')))->values());

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
            collect($alerts['check_overdue'] ?? [])->merge($alerts['recheck_overdue'] ?? [])->map(fn (Mating $m) => $goat($m->dam, 'mated '.$m->mated_on->format('j M').($m->check_due_on ? ' · check was due '.$m->check_due_on->format('j M') : ''))));
        $add('breeding', 'edd_passed', 'Kidding overdue', 'critical', 'Check the doe and record the kidding or the outcome.', '/breeding',
            collect($alerts['edd_passed'] ?? [])->map(fn (Mating $m) => $goat($m->dam, 'expected '.$m->expected_kidding_on->format('j M'))));
        $add('breeding', 'kidding_soon', 'Kidding soon', 'info', 'Prepare the kidding area and watch the doe.', '/breeding',
            collect($alerts['kidding_soon'] ?? [])->map(fn (Mating $m) => $goat($m->dam, 'expected '.$m->expected_kidding_on->format('j M'))));

        $weaningDue = Animal::active()->where('origin', 'born_on_farm')->where(fn ($q) => $q->whereNull('kid_stage')->orWhere('kid_stage', '!=', 'weaned'))
            ->whereNotNull('birth_date')->whereBetween('birth_date', [$today->copy()->subDays($org->weaning_age_days + 90)->toDateString(), $today->copy()->subDays($org->weaning_age_days)->toDateString()])
            ->whereNotIn('id', Weaning::where('status', 'completed')->select('kid_id'))
            ->whereNotIn('id', Weaning::where('status', 'postponed')->where('postponed_to', '>', $todayIso)->select('kid_id'))->orderBy('birth_date')->get();
        $add('breeding', 'weaning', 'Kids due for weaning', 'warning', 'Wean (weight, method, pen) or postpone with a reason.', '/breeding',
            $weaningDue->map(fn (Animal $a) => $goat($a, $a->birth_date->diffInDays($today).' days old')));

        // ---- Feeding ----
        if ($user->canModule('feeding')) {
            $board = Feed::board(Feed::now($org)->toDateString(), $org);
            $pens = \App\Models\Pen::with('shed')->whereIn('id', $board->keys())->get()->keyBy('id');
            $missed = $board->flatMap(fn ($meals, $penId) => collect($meals)->filter(fn ($m) => $m['status'] === 'missed')->map(fn ($m, $meal) => ['text' => $pens[$penId]?->fullName() ?? 'Pen', 'sub' => Feed::MEALS[$meal].' meal', 'url' => '/feeding']))->values();
            $add('feeding', 'feeding_missed', 'Feeding missed today', 'critical', 'Find out why and feed the pen.', '/feeding', $missed);
            $stock = Feed::stock();
            $low = FeedItem::where('active', true)->orderBy('name')->get()->filter(fn (FeedItem $i) => Feed::stockStatus($stock[$i->id] ?? 0, $i->reorder_level_kg) !== 'ok');
            $add('feeding', 'feed_low', 'Feed low, out or below zero', $low->contains(fn ($i) => ($stock[$i->id] ?? 0) <= 0) ? 'critical' : 'warning', 'Check the store and record the purchase.', '/feeding',
                $low->map(fn (FeedItem $i) => ['text' => $i->name, 'sub' => round($stock[$i->id] ?? 0, 1).' kg · reorder at '.round($i->reorder_level_kg).' kg', 'url' => '/feeding'])->values());
        }

        // ---- Herd data ----
        $goats = Animal::with('pen')->active()->orderBy('farm_number')->get();
        $tags = HerdStats::tagsFor($goats);
        $add('goats', 'missing', 'Goats missing a pen or weight', 'warning', 'Move the goat into a pen and weigh it, so feed can be planned.', '/goats?show=missing',
            $goats->filter(fn (Animal $a) => in_array('missing', $tags[$a->id], true))->map(fn (Animal $a) => $goat($a, implode(' · ', array_map(fn ($k) => HerdStats::LABELS[$k], array_intersect(['no_pen', 'no_weight'], $tags[$a->id]))))));

        // ---- Tasks ----
        $tasks = Task::where('status', 'open')->where('due_on', '<', $todayIso)->orderBy('due_on')->get();
        $add('tasks', 'tasks_overdue', 'Tasks overdue', 'warning', 'Finish or reassign the task.', '/tasks',
            $tasks->map(fn (Task $t) => ['text' => $t->title, 'sub' => 'due '.$t->due_on->format('j M'), 'url' => '/tasks']));

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($out, fn ($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);

        return $out;
    }
}
