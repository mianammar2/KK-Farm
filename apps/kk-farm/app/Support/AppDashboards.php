<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * D-257: every app opens on its own dashboard: the numbers that matter for that app (a number that is zero is not
 * shown), one or two charts, and what needs attention from the alerts that belong to it. Numbers open their records.
 * Counts come from the same tables the app's pages use, and the alerts are the Action Center's (same roles, same rules).
 */
final class AppDashboards
{
    /** app key => [title, the modules that open the app, the alert keys that belong to it] (apps.ts has the menus) */
    public const APPS = [
        'worker' => ['Tasks', ['scan', 'feeding'], ['tasks_overdue']],
        'livestock' => ['Livestock', ['goats'], ['missing', 'removal_requests']],
        'breeding' => ['Breeding', ['breeding'], ['breeding_approval', 'check_overdue']],
        'maternity' => ['Maternity', ['breeding'], ['edd_passed', 'kidding_imminent', 'kidding_soon', 'weaning']],
        'veterinary' => ['Veterinary', ['health'], ['dose_overdue', 'open_cases', 'concerns', 'care_overdue', 'checkups_due', 'withdrawal', 'medicine']],
        'feeding' => ['Feeding', ['feeding'], ['feeding_missed', 'feed_over', 'feed_low', 'ration_low_cp']],
        'housing' => ['Housing', ['sheds'], ['out_too_long']],
        'growth' => ['Growth', ['weights'], []],
        'operations' => ['Operations', ['tasks'], ['tasks_overdue']],
        'inventory' => ['Inventory', ['inventory', 'feeding', 'health'], ['feed_low', 'medicine']],
        'purchase' => ['Purchase', ['purchase', 'finance', 'goats', 'animal_purchase'], []],
        'sales' => ['Sales', ['sales', 'value', 'finance'], []],
        'accounting' => ['Accounting', ['finance'], ['unpaid']],
        'employees' => ['Employees', ['employees'], []],
        'maintenance' => ['Maintenance', ['maintenance', 'sensors'], ['sensor_alerts', 'devices_offline']],
        'reports' => ['Reports', ['reports'], []],
        'dairy' => ['Dairy', ['milking'], ['dry_off_due']],
        'fattening' => ['Fattening', ['fattening'], []],
        'welfare' => ['Welfare', ['welfare'], []],
        'environment' => ['Environment', ['environment'], []],
        'quality' => ['Quality', ['quality'], []],
        'fodder' => ['Fodder', ['fodder'], []],
        'settings' => ['Settings', ['settings'], []],
    ];

    public static function opens(User $u, string $app): bool
    {
        return isset(self::APPS[$app]) && collect(self::APPS[$app][1])->contains(fn ($m) => $u->canModule($m));
    }

    /** @return array{title: string, kpis: list<array>, charts: list<array>, alerts: list<array>} */
    public static function build(User $u, string $app): array
    {
        $today = now()->toDateString();
        $month = now()->startOfMonth()->toDateString();
        $d = match ($app) {
            'worker' => self::worker($today),
            'livestock' => self::livestock(),
            'breeding' => self::breeding($today, $month),
            'maternity' => self::maternity($today, $month),
            'veterinary' => self::veterinary($today),
            'feeding' => self::feeding($today),
            'housing' => self::housing(),
            'growth' => self::growth(),
            'operations' => self::operations($u, $today),
            'inventory' => self::inventory(),
            'purchase' => self::purchase($u, $month),
            'sales' => self::sales($month),
            'accounting' => self::accounting($month),
            'employees' => self::employees($today),
            'maintenance' => self::maintenance($today),
            'reports' => self::reports(),
            'dairy' => self::dairy($today),
            'fattening' => self::fattening(),
            'welfare' => self::welfare($today),
            'environment' => self::environment($today),
            'quality' => self::quality(),
            'fodder' => self::fodder($today),
            'settings' => self::settings($u),
            default => ['kpis' => [], 'charts' => []],
        };
        $keys = self::APPS[$app][2];
        $alerts = $keys ? collect(ActionCenter::items($u))->whereIn('key', $keys)->values()->all() : [];

        return [
            'title' => self::APPS[$app][0],
            // zeros and empty values are not shown (dashboard rule)
            'kpis' => collect($d['kpis'])->filter(fn ($k) => $k['label'] !== '' && $k['value'] !== null && ! (is_numeric($k['value']) && (float) $k['value'] == 0.0) && $k['value'] !== 'PKR 0')->values()->all(),
            'charts' => collect($d['charts'])->filter(fn ($c) => collect($c['series'])->flatMap(fn ($s) => $s['data'])->sum() > 0)->values()->all(),
            'alerts' => $alerts,
        ];
    }

    // ---------------------------------------------------------------- helpers

    private static function k(string $label, $value, ?string $url = null, ?string $tone = null, ?string $note = null): array
    {
        return ['label' => $label, 'value' => $value, 'url' => $url, 'tone' => $tone, 'note' => $note];
    }

    private static function pkr(float $amount): string
    {
        return 'PKR '.number_format(round($amount));
    }

    private static function chart(string $title, string $kind, array $labels, array $series, ?string $unit = null): array
    {
        return ['title' => $title, 'kind' => $kind, 'labels' => array_values($labels), 'series' => $series, 'unit' => $unit];
    }

    /** day labels (oldest first) and a count per day from a query that groups by a date column */
    private static function perDay(int $days, \Closure $counts): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $rows = $counts($from->toDateString());
        $labels = [];
        $data = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i);
            $labels[] = $day->format('d/m');
            $data[] = round((float) ($rows[$day->toDateString()] ?? 0), 1);
        }

        return [$labels, $data];
    }

    private static function perMonth(int $months, \Closure $sum): array
    {
        $from = now()->startOfMonth()->subMonths($months - 1);
        $rows = $sum($from->toDateString());
        $labels = [];
        $data = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $from->copy()->addMonths($i);
            $labels[] = $m->format('m/y');
            $data[] = round((float) ($rows[$m->format('Y-m')] ?? 0), 1);
        }

        return [$labels, $data];
    }

    private static function byDay(string $table, string $col, string $from, string $agg = 'count(*)', ?\Closure $where = null)
    {
        $q = DB::table($table)->where($col, '>=', $from)->selectRaw("{$col}::date as d, {$agg} as n")->groupByRaw("{$col}::date");
        if ($where) {
            $where($q);
        }

        return $q->pluck('n', 'd');
    }

    private static function byMonth(string $table, string $col, string $from, string $agg = 'count(*)', ?\Closure $where = null)
    {
        $q = DB::table($table)->where($col, '>=', $from)->selectRaw("to_char({$col}, 'YYYY-MM') as m, {$agg} as n")->groupByRaw("to_char({$col}, 'YYYY-MM')");
        if ($where) {
            $where($q);
        }

        return $q->pluck('n', 'm');
    }

    private static function active()
    {
        return Animal::where('status', 'active');
    }

    // ---------------------------------------------------------------- the apps

    private static function worker(string $today): array
    {
        $weighed = DB::table('weight_records')->whereDate('measured_on', $today)->count();
        $checks = DB::table('health_checkups')->whereDate('checked_on', $today)->count();
        [$labels, $w] = self::perDay(7, fn ($f) => self::byDay('weight_records', 'measured_on', $f));
        [, $c] = self::perDay(7, fn ($f) => self::byDay('health_checkups', 'checked_on', $f));

        return ['kpis' => [
            self::k('Weighed today', $weighed, '/weights'),
            self::k('Checkups today', $checks, '/health?tab=checkups'),
            self::k('Heats seen today', DB::table('heat_records')->whereDate('observed_on', $today)->count(), '/breeding?tab=heat'),
            self::k('Matings today', DB::table('matings')->whereDate('mated_on', $today)->count(), '/breeding?tab=cycle'),
            self::k('Milk today (L)', round((float) DB::table('milk_records')->whereDate('milked_on', $today)->whereNull('supersedes_id')->sum('litres'), 1), '/milking'),
            self::k('Care given today', DB::table('health_records')->whereDate('occurred_on', $today)->whereIn('kind', ['vaccination', 'deworming', 'hoof_care', 'examination', 'other_care'])->count(), '/health?tab=care'),
            self::k('Moves today', DB::table('animal_movements')->whereDate('moved_on', $today)->where('type', 'transfer')->count(), '/movements'),
        ], 'charts' => [self::chart('Goats handled, last 7 days', 'bar', $labels, [['name' => 'Weighed', 'data' => $w], ['name' => 'Checked', 'data' => $c]])]];
    }

    private static function livestock(): array
    {
        $goats = self::active()->with('breed')->get();
        $byBreed = $goats->countBy(fn ($a) => $a->breed?->name ?? 'Unknown')->sortDesc()->take(8);
        $byStage = $goats->countBy(fn ($a) => AgeStages::codeFor($a->birth_date) ?? 'No age');
        $stages = AgeStages::stages()->pluck('code')->push('No age')->filter(fn ($c) => isset($byStage[$c]));
        $tags = HerdStats::tagsFor($goats);
        $count = fn (string $tag) => $tags->filter(fn ($t) => in_array($tag, $t, true))->count();

        return ['kpis' => [
            self::k('Goats on the farm', $goats->count(), '/goats'),
            self::k('Females', $goats->where('sex', 'female')->count(), '/goats?show=female'),
            self::k('Males', $goats->where('sex', 'male')->count(), '/goats?show=male'),
            self::k('Pregnant', $count('pregnant'), '/goats?show=pregnant'),
            self::k('Need attention', $count('attention'), '/goats?show=attention', 'warning'),
            self::k('Missing data', $count('missing'), '/goats?show=missing', 'warning'),
            self::k('Newborns to complete', $count('newborn'), '/goats?show=newborn'),
            self::k('Left the farm this year', Animal::where('status', '!=', 'active')->whereYear('exit_date', now()->year)->count(), '/reports?section=exits'),
        ], 'charts' => [
            self::chart('Herd by breed', 'donut', $byBreed->keys()->all(), [['name' => 'Goats', 'data' => $byBreed->values()->all()]]),
            self::chart('Herd by age stage', 'bar', $stages->values()->all(), [['name' => 'Goats', 'data' => $stages->map(fn ($c) => $byStage[$c])->values()->all()]]),
        ]];
    }

    private static function breeding(string $today, string $month): array
    {
        [$labels, $m] = self::perMonth(6, fn ($f) => self::byMonth('matings', 'mated_on', $f));

        return ['kpis' => [
            self::k('Confirmed pregnant', DB::table('matings')->where('status', 'pregnant')->count(), '/goats?show=pregnant'),
            self::k('Waiting for a pregnancy check', DB::table('matings')->where('status', 'open')->count(), '/breeding?tab=cycle'),
            self::k('Check overdue', DB::table('matings')->where('status', 'open')->whereDate('check_due_on', '<', $today)->count(), '/breeding?tab=cycle', 'warning'),
            self::k('In heat (last 2 days)', DB::table('heat_records')->whereDate('observed_on', '>=', now()->subDays(2))->distinct()->count('dam_id'), '/breeding?tab=heat'),
            self::k('Matings this month', DB::table('matings')->whereDate('mated_on', '>=', $month)->count(), '/breeding?tab=cycle'),
            self::k('Kidding in the next 30 days', DB::table('matings')->where('status', 'pregnant')->whereBetween('expected_kidding_on', [$today, now()->addDays(30)->toDateString()])->count(), '/breeding?tab=calendar'),
        ], 'charts' => [self::chart('Matings per month', 'bar', $labels, [['name' => 'Matings', 'data' => $m]])]];
    }

    private static function maternity(string $today, string $month): array
    {
        $since = now()->subDays(90)->toDateString();
        [$labels, $alive] = self::perMonth(6, fn ($f) => self::byMonth('kiddings', 'kidded_on', $f, 'sum(born_alive)'));
        [, $still] = self::perMonth(6, fn ($f) => self::byMonth('kiddings', 'kidded_on', $f, 'sum(stillborn)'));

        return ['kpis' => [
            self::k('Kidding in the next 30 days', DB::table('matings')->where('status', 'pregnant')->whereBetween('expected_kidding_on', [$today, now()->addDays(30)->toDateString()])->count(), '/breeding?tab=calendar'),
            self::k('Kidding overdue', DB::table('matings')->where('status', 'pregnant')->whereDate('expected_kidding_on', '<', $today)->count(), '/breeding?tab=calendar', 'warning'),
            self::k('Kiddings this month', DB::table('kiddings')->whereDate('kidded_on', '>=', $month)->count(), '/breeding?tab=kidding'),
            self::k('Kids born alive (90 days)', (int) DB::table('kiddings')->whereDate('kidded_on', '>=', $since)->sum('born_alive'), '/breeding?tab=kidding'),
            self::k('Stillborn (90 days)', (int) DB::table('kiddings')->whereDate('kidded_on', '>=', $since)->sum('stillborn'), '/breeding?tab=kidding', 'warning'),
            self::k('Does milking', DB::table('lactations')->whereNull('dried_on')->count(), '/milking'),
        ], 'charts' => [self::chart('Kids born per month', 'bar', $labels, [['name' => 'Alive', 'data' => $alive], ['name' => 'Stillborn', 'data' => $still]])]];
    }

    private static function veterinary(string $today): array
    {
        $active = \App\Models\HealthCase::ACTIVE;
        $byCondition = DB::table('health_cases')->whereIn('status', $active)->selectRaw('condition, count(*) as n')->groupBy('condition')->orderByDesc('n')->limit(8)->pluck('n', 'condition');
        [$normal, $high] = \App\Models\HealthCheckup::NORMAL['temperature_f'];

        return ['kpis' => [
            self::k('Open cases', DB::table('health_cases')->whereIn('status', $active)->count(), '/health?tab=cases', 'warning'),
            self::k('Under treatment', DB::table('health_cases')->where('status', 'under_treatment')->count(), '/health?tab=cases'),
            self::k('Reported concerns', DB::table('health_records')->where('kind', 'observation')->where('status', 'open')->count(), '/health?tab=reports'),
            self::k('Under withdrawal', DB::table('treatments')->where(fn ($q) => $q->whereDate('meat_clear_on', '>=', $today)->orWhereDate('milk_clear_on', '>=', $today))->distinct()->count('animal_id'), '/health?tab=withdrawal'),
            self::k('Checkups this week', DB::table('health_checkups')->whereDate('checked_on', '>=', now()->subDays(6))->count(), '/health?tab=checkups'),
            self::k('Fever readings (7 days)', DB::table('health_checkups')->whereDate('checked_on', '>=', now()->subDays(6))->where(fn ($q) => $q->where('temperature_f', '>', $high)->orWhere('temperature_f', '<', $normal))->count(), '/health?tab=checkups', 'critical'),
            self::k('Deaths (30 days)', Animal::where('status', 'dead')->whereDate('exit_date', '>=', now()->subDays(30))->count(), '/health?tab=mortality', 'critical'),
        ], 'charts' => [self::chart('Open cases by condition', 'bar', $byCondition->keys()->all(), [['name' => 'Cases', 'data' => $byCondition->values()->all()]])]];
    }

    private static function feeding(string $today): array
    {
        [$labels, $done] = self::perDay(7, fn ($f) => self::byDay('feeding_logs', 'fed_on', $f, 'count(*)', fn ($q) => $q->where('status', 'done')));

        return ['kpis' => [
            self::k('Feedings done today', DB::table('feeding_logs')->whereDate('fed_on', $today)->where('status', 'done')->count(), '/feeding?tab=now'),
            self::k('Rations in use', DB::table('ration_plans')->where('status', 'approved')->count(), '/feeding?tab=rations'),
            self::k('Rations waiting for approval', DB::table('ration_plans')->where('status', 'draft')->count(), '/feeding?tab=rations', 'warning'),
            self::k('Feed items', DB::table('feed_items')->where('active', true)->count(), '/feeding?tab=store'),
            self::k('TMR batches this week', DB::table('tmr_batches')->whereDate('mixed_on', '>=', now()->subDays(6))->count(), '/feeding?tab=tmr'),
            self::k('Feed lots on hold', DB::table('feed_lots')->whereIn('status', ['quarantine', 'hold', 'pending'])->count(), '/feeding/lots', 'warning'),
        ], 'charts' => [self::chart('Feedings done, last 7 days', 'bar', $labels, [['name' => 'Feedings', 'data' => $done]])]];
    }

    private static function housing(): array
    {
        $pens = \App\Models\Pen::with('shed')->where('kind', 'pen')->get();
        $count = self::active()->whereNotNull('pen_id')->selectRaw('pen_id, count(*) as n')->groupBy('pen_id')->pluck('n', 'pen_id');
        $full = $pens->filter(fn ($p) => $p->capacity && ($count[$p->id] ?? 0) >= $p->capacity);
        $over = $pens->filter(fn ($p) => $p->capacity && ($count[$p->id] ?? 0) > $p->capacity);
        $top = $pens->sortByDesc(fn ($p) => $count[$p->id] ?? 0)->take(10);

        return ['kpis' => [
            self::k('Sheds', DB::table('sheds')->count(), '/sheds'),
            self::k('Pens', $pens->count(), '/sheds'),
            self::k('Goats housed', (int) $count->sum(), '/housing'),
            self::k('Pens full', $full->count(), '/housing', 'warning'),
            self::k('Pens over capacity', $over->count(), '/housing', 'critical'),
            self::k('Goats without a pen', self::active()->whereNull('pen_id')->count(), '/goats?show=no_pen', 'warning'),
            self::k('Pens not in use (cleaning, repair)', $pens->whereNotIn('status', [null, 'active'])->count(), '/housing'),
        ], 'charts' => [self::chart('Goats per pen (fullest 10)', 'bar', $top->map(fn ($p) => $p->name)->values()->all(), [
            ['name' => 'Goats', 'data' => $top->map(fn ($p) => (int) ($count[$p->id] ?? 0))->values()->all()],
            ['name' => 'Capacity', 'data' => $top->map(fn ($p) => (int) ($p->capacity ?? 0))->values()->all()],
        ])]];
    }

    private static function growth(): array
    {
        $since = now()->subDays(30)->toDateString();
        $latest = DB::table('weight_records')->whereNull('review_status')->orWhere('review_status', 'accepted')->orderBy('animal_id')->orderByDesc('measured_on')->orderByDesc('id')
            ->get(['animal_id', 'weight_kg', 'measured_on'])->groupBy('animal_id')->map(fn ($r) => $r->take(2)->values());
        $gaining = $latest->filter(fn ($r) => $r->count() === 2 && $r[0]->weight_kg > $r[1]->weight_kg)->count();
        $losing = $latest->filter(fn ($r) => $r->count() === 2 && $r[0]->weight_kg < $r[1]->weight_kg)->count();
        [$labels, $w] = self::perDay(14, fn ($f) => self::byDay('weight_records', 'measured_on', $f));

        return ['kpis' => [
            self::k('Weighed (30 days)', DB::table('weight_records')->whereDate('measured_on', '>=', $since)->distinct()->count('animal_id'), '/weights'),
            self::k('Gaining since last weighing', $gaining, '/dashboard/weight-change', 'good'),
            self::k('Losing since last weighing', $losing, '/dashboard/weight-change', 'critical'),
            self::k('No weight yet', self::active()->whereNotIn('id', DB::table('weight_records')->select('animal_id'))->count(), '/goats?show=no_weight', 'warning'),
            self::k('Held weights to review', DB::table('weight_records')->where('review_status', 'held')->count(), '/weights/review', 'warning'),
            self::k('Open growth interventions', DB::table('growth_interventions')->where('status', 'open')->count(), '/growth?tab=interventions'),
        ], 'charts' => [self::chart('Weighings, last 14 days', 'bar', $labels, [['name' => 'Weighings', 'data' => $w]])]];
    }

    private static function operations(User $u, string $today): array
    {
        $open = \App\Models\Task::visibleTo($u)->whereIn('status', \App\Models\Task::OPEN);
        $byDept = (clone $open)->leftJoin('departments', 'departments.id', '=', 'tasks.department_id')->selectRaw("coalesce(departments.name, 'No department') as d, count(*) as n")->groupBy('d')->pluck('n', 'd');

        return ['kpis' => [
            self::k('Open tasks', (clone $open)->count(), '/tasks'),
            self::k('Overdue', (clone $open)->whereDate('due_on', '<', $today)->count(), '/tasks?tab=overdue', 'critical'),
            self::k('Due today', (clone $open)->whereDate('due_on', $today)->count(), '/tasks?tab=mine'),
            self::k('Done this week', \App\Models\Task::visibleTo($u)->where('status', 'completed')->where('completed_at', '>=', now()->subDays(6)->startOfDay())->count(), '/tasks?tab=closed', 'good'),
            self::k('Open incidents', DB::table('incidents')->whereNotIn('status', ['resolved', 'closed'])->count(), '/operations?tab=incidents', 'warning'),
            self::k('Handovers this week', DB::table('shift_handovers')->whereDate('handed_on', '>=', now()->subDays(6))->count(), '/operations?tab=handover'),
        ], 'charts' => [self::chart('Open tasks by department', 'bar', $byDept->keys()->all(), [['name' => 'Tasks', 'data' => $byDept->values()->all()]])]];
    }

    private static function inventory(): array
    {
        $rows = collect(Stores::overview());
        $byStore = $rows->countBy('kind');
        $low = $rows->whereIn('status', ['low', 'out', 'negative']);

        return ['kpis' => [
            self::k('Stock items', $rows->count(), '/inventory'),
            self::k('Low or out', $low->count(), '/inventory?tab=reorder', 'warning'),
            self::k('Feed items low', $low->where('kind', 'feed')->count(), '/feeding?tab=store', 'warning'),
            self::k('Medicines low', $low->where('kind', 'medicine')->count(), '/health?tab=store', 'warning'),
            self::k('General store low', $low->where('kind', 'store')->count(), '/inventory?tab=general', 'warning'),
            self::k('Stock moves this week', DB::table('store_moves')->whereDate('moved_on', '>=', now()->subDays(6))->count() + DB::table('feed_stock_movements')->whereDate('moved_on', '>=', now()->subDays(6))->count() + DB::table('medicine_movements')->whereDate('moved_on', '>=', now()->subDays(6))->count(), '/inventory?tab=moves'),
        ], 'charts' => [self::chart('Items by store', 'donut', $byStore->keys()->map(fn ($k) => ['feed' => 'Feed', 'medicine' => 'Medicine', 'store' => 'General store'][$k] ?? $k)->all(), [['name' => 'Items', 'data' => $byStore->values()->all()]])]];
    }

    private static function purchase(User $u, string $month): array
    {
        $byStatus = DB::table('purchase_orders')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $labels = \App\Models\PurchaseOrder::STATUSES;

        return ['kpis' => [
            self::k('Draft orders', (int) ($byStatus['draft'] ?? 0), '/purchase'),
            self::k('Ordered, not received', (int) ($byStatus['ordered'] ?? 0), '/purchase', 'warning'),
            self::k('Received this month', DB::table('purchase_orders')->where('status', 'received')->whereDate('received_on', '>=', $month)->count(), '/purchase'),
            self::k('Suppliers', DB::table('suppliers')->where('active', true)->count(), '/purchase?tab=suppliers'),
            self::k('Goat lots waiting for approval', DB::table('animal_purchase_lots')->where('status', 'requested')->count(), '/purchase/animals', 'warning'),
            self::k('Goat lots to receive', DB::table('animal_purchase_lots')->where('status', 'approved')->count(), '/purchase/animals'),
            $u->canModule('finance') ? self::k('Spent this month', self::pkr((float) DB::table('ledger_entries')->where('direction', 'out')->whereDate('occurred_on', '>=', $month)->whereNull('reverses_id')->sum('amount')), '/finance') : self::k('', null),
        ], 'charts' => [self::chart('Purchase orders by status', 'donut', $byStatus->keys()->map(fn ($s) => $labels[$s] ?? $s)->all(), [['name' => 'Orders', 'data' => $byStatus->values()->all()]])]];
    }

    private static function sales(string $month): array
    {
        [$labels, $pkr] = self::perMonth(6, fn ($f) => self::byMonth('sales', 'sold_on', $f, 'sum(gross_minor) / 100.0'));

        return ['kpis' => [
            self::k('Sales this month', DB::table('sales')->whereDate('sold_on', '>=', $month)->count(), '/sales'),
            self::k('Sold this month', self::pkr((float) DB::table('sales')->whereDate('sold_on', '>=', $month)->sum('gross_minor') / 100), '/sales?tab=monthly', 'good'),
            self::k('Quotes waiting for approval', DB::table('sale_orders')->where('status', 'quote')->count(), '/sales/orders', 'warning'),
            self::k('Orders to dispatch', DB::table('sale_orders')->where('status', 'reserved')->count(), '/sales/orders'),
            self::k('Goats sale ready', count(array_filter(HerdStats::lifecycle(), fn ($s) => ($s['sale'] ?? null) === 'sale_ready')), '/goats?show=life_sale_sale_ready', 'good'),
            self::k('Customers', DB::table('customers')->where('active', true)->count(), '/sales?tab=customers'),
        ], 'charts' => [self::chart('Sales per month (PKR)', 'bar', $labels, [['name' => 'PKR', 'data' => $pkr]], 'PKR')]];
    }

    private static function accounting(string $month): array
    {
        $sum = fn (string $dir, string $from) => (float) DB::table('ledger_entries')->where('direction', $dir)->whereDate('occurred_on', '>=', $from)->whereNull('reverses_id')
            ->whereNotIn('id', DB::table('ledger_entries')->whereNotNull('reverses_id')->select('reverses_id'))->sum('amount');
        $in = $sum('in', $month);
        $out = $sum('out', $month);
        [$labels, $inc] = self::perMonth(6, fn ($f) => self::byMonth('ledger_entries', 'occurred_on', $f, 'sum(amount)', fn ($q) => $q->where('direction', 'in')->whereNull('reverses_id')));
        [, $exp] = self::perMonth(6, fn ($f) => self::byMonth('ledger_entries', 'occurred_on', $f, 'sum(amount)', fn ($q) => $q->where('direction', 'out')->whereNull('reverses_id')));

        return ['kpis' => [
            self::k('Income this month', self::pkr($in), '/finance', 'good'),
            self::k('Expenses this month', self::pkr($out), '/finance'),
            self::k($in - $out >= 0 ? 'Profit this month' : 'Loss this month', self::pkr(abs($in - $out)), '/finance/books?tab=pl', $in - $out >= 0 ? 'good' : 'critical'),
            self::k('Expenses not fully paid', DB::table('ledger_entries')->where('direction', 'out')->whereIn('payment_status', ['pending', 'partial'])->whereNull('reverses_id')->count(), '/finance', 'warning'),
        ], 'charts' => [self::chart('Income and expenses per month (PKR)', 'bar', $labels, [['name' => 'Income', 'data' => $inc], ['name' => 'Expenses', 'data' => $exp]], 'PKR')]];
    }

    private static function employees(string $today): array
    {
        [$labels, $present] = self::perDay(7, fn ($f) => self::byDay('attendances', 'work_on', $f, 'count(*)', fn ($q) => $q->whereIn('status', ['present', 'half_day'])));

        return ['kpis' => [
            self::k('Employees', DB::table('employees')->where('is_active', true)->count(), '/employees'),
            self::k('Present today', DB::table('attendances')->whereDate('work_on', $today)->whereIn('status', ['present', 'half_day'])->count(), '/hr?tab=attendance', 'good'),
            self::k('Absent today', DB::table('attendances')->whereDate('work_on', $today)->where('status', 'absent')->count(), '/hr?tab=attendance', 'warning'),
            self::k('On leave today', DB::table('leave_requests')->where('status', 'approved')->whereDate('from_on', '<=', $today)->whereDate('to_on', '>=', $today)->count(), '/hr?tab=leave'),
            self::k('Leave requests to decide', DB::table('leave_requests')->where('status', 'pending')->count(), '/hr?tab=leave', 'warning'),
            self::k('Trainings expiring (30 days)', DB::table('staff_trainings')->whereBetween('expires_on', [$today, now()->addDays(30)->toDateString()])->count(), '/hr/training', 'warning'),
        ], 'charts' => [self::chart('Present, last 7 days', 'bar', $labels, [['name' => 'Present', 'data' => $present]])]];
    }

    private static function maintenance(string $today): array
    {
        $open = DB::table('maintenance_jobs')->whereNotIn('status', ['done', 'closed', 'cancelled']);
        $byPriority = (clone $open)->selectRaw('priority, count(*) as n')->groupBy('priority')->pluck('n', 'priority');

        return ['kpis' => [
            self::k('Open jobs', (clone $open)->count(), '/maintenance?tab=jobs', 'warning'),
            self::k('High or critical', (clone $open)->whereIn('priority', ['high', 'critical'])->count(), '/maintenance?tab=jobs', 'critical'),
            self::k('Services due (7 days)', DB::table('assets')->whereNotNull('next_service_on')->whereDate('next_service_on', '<=', now()->addDays(7))->count(), '/maintenance?tab=due'),
            self::k('Done this month', DB::table('maintenance_jobs')->whereDate('done_on', '>=', now()->startOfMonth())->count(), '/maintenance?tab=history', 'good'),
            self::k('Sensor alerts open', DB::table('sensor_alerts')->where('status', 'open')->count(), '/sensors?tab=alerts', 'warning'),
            self::k('Devices offline', DB::table('devices')->where('status', 'offline')->count(), '/sensors?tab=devices', 'critical'),
        ], 'charts' => [self::chart('Open jobs by priority', 'donut', $byPriority->keys()->map(fn ($p) => ucfirst($p))->all(), [['name' => 'Jobs', 'data' => $byPriority->values()->all()]])]];
    }

    private static function reports(): array
    {
        $year = now()->startOfYear()->toDateString();
        $byBreed = self::active()->leftJoin('breeds', 'breeds.id', '=', 'animals.breed_id')->selectRaw("coalesce(breeds.name, 'Unknown') as b, count(*) as n")->groupBy('b')->orderByDesc('n')->limit(8)->pluck('n', 'b');

        return ['kpis' => [
            self::k('Goats on the farm', self::active()->count(), '/reports?section=herd'),
            self::k('Kids born this year', (int) DB::table('kiddings')->whereDate('kidded_on', '>=', $year)->sum('born_alive'), '/breeding?tab=performance'),
            self::k('Goats sold this year', DB::table('sales')->whereDate('sold_on', '>=', $year)->whereNotNull('animal_id')->count(), '/reports?section=exits'),
            self::k('Deaths this year', Animal::where('status', 'dead')->whereDate('exit_date', '>=', $year)->count(), '/reports?section=exits', 'critical'),
        ], 'charts' => [self::chart('Herd by breed', 'donut', $byBreed->keys()->all(), [['name' => 'Goats', 'data' => $byBreed->values()->all()]])]];
    }

    private static function dairy(string $today): array
    {
        $litres = fn ($q) => $q->whereNull('supersedes_id')->where('discarded', false);
        [$labels, $l] = self::perDay(14, fn ($f) => self::byDay('milk_records', 'milked_on', $f, 'sum(litres)', $litres));

        return ['kpis' => [
            self::k('Litres today', round((float) $litres(DB::table('milk_records')->whereDate('milked_on', $today))->sum('litres'), 1), '/milking'),
            self::k('Litres this week', round((float) $litres(DB::table('milk_records')->whereDate('milked_on', '>=', now()->subDays(6)))->sum('litres'), 1), '/milking'),
            self::k('Does milking', DB::table('lactations')->whereNull('dried_on')->count(), '/milking'),
            self::k('Milk lots on hold', DB::table('milk_lots')->whereIn('status', ['hold', 'pending_qc'])->count(), '/dairy?tab=lots', 'warning'),
            self::k('Discarded today (L)', round((float) DB::table('milk_records')->whereDate('milked_on', $today)->whereNull('supersedes_id')->where('discarded', true)->sum('litres'), 1), '/milking', 'critical'),
        ], 'charts' => [self::chart('Litres per day, last 14 days', 'line', $labels, [['name' => 'Litres', 'data' => $l]], 'L')]];
    }

    private static function fattening(): array
    {
        $batches = DB::table('fattening_batches')->whereNotIn('status', ['closed'])->get(['id', 'code', 'name', 'status']);
        $inBatch = DB::table('fattening_batch_animals')->whereNull('left_on')->selectRaw('batch_id, count(*) as n')->groupBy('batch_id')->pluck('n', 'batch_id');

        return ['kpis' => [
            self::k('Active batches', $batches->where('status', 'active')->count(), '/fattening?tab=batches'),
            self::k('Goats in batches', (int) $inBatch->sum(), '/fattening?tab=batches'),
            self::k('Batches at target', $batches->where('status', 'target_reached')->count(), '/fattening?tab=batches', 'good'),
            self::k('Batches sale ready', $batches->where('status', 'sale_ready')->count(), '/fattening?tab=batches', 'good'),
            self::k('Closed this month', DB::table('fattening_batches')->where('status', 'closed')->whereDate('closed_on', '>=', now()->startOfMonth())->count(), '/fattening?tab=profit'),
        ], 'charts' => [self::chart('Goats per batch', 'bar', $batches->map(fn ($b) => $b->code)->all(), [['name' => 'Goats', 'data' => $batches->map(fn ($b) => (int) ($inBatch[$b->id] ?? 0))->all()]])]];
    }

    private static function welfare(string $today): array
    {
        $open = DB::table('welfare_cases')->where('status', '!=', 'closed');
        $bySeverity = (clone $open)->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity');

        return ['kpis' => [
            self::k('Open welfare cases', (clone $open)->count(), '/welfare?tab=cases', 'warning'),
            self::k('High or critical', (clone $open)->whereIn('severity', ['high', 'critical'])->count(), '/welfare?tab=cases', 'critical'),
            self::k('With the vet', (clone $open)->where('status', 'vet_referral')->count(), '/welfare?tab=cases'),
            self::k('Checks this week', DB::table('welfare_checks')->whereDate('checked_on', '>=', now()->subDays(6))->count(), '/welfare?tab=checks'),
            self::k('Lame or injured (30 days)', DB::table('welfare_checks')->whereDate('checked_on', '>=', now()->subDays(30))->where(fn ($q) => $q->where('lameness', '>', 0)->orWhere('injury', '!=', 'none'))->count(), '/welfare?tab=checks', 'warning'),
            self::k('Closed this month', DB::table('welfare_cases')->where('status', 'closed')->whereDate('closed_at', '>=', now()->startOfMonth())->count(), '/welfare?tab=closed', 'good'),
        ], 'charts' => [self::chart('Open cases by severity', 'donut', $bySeverity->keys()->map(fn ($s) => ucfirst($s))->all(), [['name' => 'Cases', 'data' => $bySeverity->values()->all()]])]];
    }

    private static function environment(string $today): array
    {
        [$labels, $temp] = self::perDay(7, fn ($f) => self::byDay('environment_rounds', 'round_at', $f, 'max(temperature_c)'));

        return ['kpis' => [
            self::k('Shed rounds today', DB::table('environment_rounds')->whereDate('round_at', $today)->count(), '/environment?tab=records'),
            self::k('Open water, heat or waste issues', DB::table('environment_issues')->whereNotIn('status', ['resolved', 'verified', 'closed'])->count(), '/environment?tab=issues', 'warning'),
            self::k('Heat risk rounds (7 days)', DB::table('environment_rounds')->where('round_at', '>=', now()->subDays(6)->startOfDay())->where('climate_risk', true)->count(), '/environment?tab=records', 'warning'),
            self::k('Water tests failed (90 days)', DB::table('water_tests')->whereDate('sampled_on', '>=', now()->subDays(90))->where('verdict', 'unsafe')->count(), '/environment?tab=tests', 'critical'),
            self::k('Waste disposals this month', DB::table('waste_disposals')->whereDate('disposed_on', '>=', now()->startOfMonth())->count(), '/waste'),
        ], 'charts' => [self::chart('Highest shed temperature, last 7 days (°C)', 'line', $labels, [['name' => '°C', 'data' => $temp]], '°C')]];
    }

    private static function quality(): array
    {
        $open = DB::table('quality_cases')->where('status', '!=', 'closed');
        $bySource = (clone $open)->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source');
        $sources = \App\Models\QualityCase::SOURCES;

        return ['kpis' => [
            self::k('Open quality cases', (clone $open)->count(), '/quality?tab=cases', 'warning'),
            self::k('High or critical', (clone $open)->whereIn('severity', ['high', 'critical'])->count(), '/quality?tab=cases', 'critical'),
            self::k('Active holds', DB::table('quality_holds')->whereNull('released_at')->count(), '/quality?tab=holds', 'warning'),
            self::k('Closed (30 days)', DB::table('quality_cases')->where('status', 'closed')->where('closed_at', '>=', now()->subDays(30))->count(), '/quality?tab=closed', 'good'),
        ], 'charts' => [self::chart('Open cases by source', 'donut', $bySource->keys()->map(fn ($s) => is_array($sources[$s] ?? null) ? ($sources[$s][0] ?? $s) : ($sources[$s] ?? $s))->all(), [['name' => 'Cases', 'data' => $bySource->values()->all()]])]];
    }

    private static function fodder(string $today): array
    {
        $byStatus = DB::table('fodder_crops')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return ['kpis' => [
            self::k('Crops growing', (int) ($byStatus['growing'] ?? 0), '/fodder?tab=crops'),
            self::k('Harvest due (30 days)', DB::table('fodder_crops')->where('status', 'growing')->whereBetween('expected_harvest_on', [$today, now()->addDays(30)->toDateString()])->count(), '/fodder?tab=crops'),
            self::k('Lots waiting for tests', DB::table('fodder_lots')->where('status', 'quarantine')->count(), '/fodder?tab=lots', 'warning'),
            self::k('Fields', DB::table('fodder_fields')->where('active', true)->count(), '/fodder?tab=fields'),
            self::k('Harvested this month (KG)', round((float) DB::table('fodder_lots')->whereDate('harvested_on', '>=', now()->startOfMonth())->sum('fresh_kg')), '/fodder?tab=report'),
        ], 'charts' => [self::chart('Crops by status', 'donut', $byStatus->keys()->map(fn ($s) => ucfirst(str_replace('_', ' ', $s)))->all(), [['name' => 'Crops', 'data' => $byStatus->values()->all()]])]];
    }

    private static function settings(User $u): array
    {
        $users = User::where('org_id', $u->org_id)->get();

        return ['kpis' => [
            self::k('Users', $users->count(), '/settings/users'),
            self::k('Can sign in', $users->where('active', true)->count(), '/settings/users?status=active'),
            self::k("Can't sign in", $users->where('active', false)->count(), '/settings/users?status=disabled', 'warning'),
            self::k('Never signed in', $users->whereNull('last_login_at')->count(), '/settings/users?status=never', 'warning'),
            self::k('With custom access', $users->filter(fn ($x) => ! empty($x->module_access) || ! empty($x->gate_access) || Pages::userTicks($x))->count(), '/settings/users?status=custom'),
            self::k('Breeds', DB::table('breeds')->count(), '/settings/breeds'),
            self::k('Departments', DB::table('departments')->count(), '/settings/users?tab=departments'),
        ], 'charts' => [self::chart('Users by role', 'donut', collect(config('kkfarm.roles'))->only($users->pluck('role')->unique()->all())->values()->all(),
            [['name' => 'Users', 'data' => collect(config('kkfarm.roles'))->only($users->pluck('role')->unique()->all())->keys()->map(fn ($r) => $users->where('role', $r)->count())->values()->all()]])]];
    }
}
