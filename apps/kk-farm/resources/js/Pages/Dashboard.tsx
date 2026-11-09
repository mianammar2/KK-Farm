import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { LIVE_MS } from '../lib/live';
import { inScannerApp } from '../lib/native';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import Chart from '../components/LazyChart';
import { axis } from '../lib/chartTheme';
import ShedBoard, { type ShedBoardData } from '../components/ShedBoard';
import { Empty, GoatLabel, monthLabel, PageHeader, Panel, pkrShort, Stat, StatRow } from '../components/ui';
import type { Health, SharedProps } from '../types';
import { titleCase } from '../lib/titleCase';

type Meal = 'morning' | 'midday' | 'evening';
type FeedStatus = 'done' | 'late' | 'missed' | 'due' | 'upcoming' | 'none';
type Balance = 'over' | 'short' | 'ok' | null;
type Feeding = { meal: Meal; meals: Record<Meal, string>; times: Record<Meal, string>; plannedKg: number; givenKg: number; cost: number; lowStock: string[]; overPens: number;
    pens: Array<{ id: number; name: string; goats: number; onMilk: number; dayPlanned: number; dayGiven: number; over: boolean; diff: number; meals: Record<Meal, { status: FeedStatus; planned: number; given: number; balance: Balance; diff: number }> }> };
type ActionSummary = { key: string; label: string; count: number; severity: 'critical' | 'warning' | 'info' };
type ReqGoat = { cp: number | null; cpKg: number; cpStandard: string | null; id: string; number: string; group: string | null; weight: number | null; state: 'planned' | 'milk' | 'no_ration' | 'no_weight'; rule: string; kgDay: number; kgMonth: number; min: number; max: number; feeds: string };
type ReqPen = { id: number | null; name: string; kgDay: number; cpDay: number; kgMonth: number; givenToday: number; balance: Balance; diff: number; cost: number; count: number; unplanned: number; goats: ReqGoat[] };
type ReqShed = { id: number | null; name: string; typeName: string | null; kgDay: number; cpDay: number; kgMonth: number; cost: number; count: number; feeds: Array<{ name: string; kg: number }>; pens: ReqPen[] };
type Supply = { cpDay: number | null; cpMonth: number | null; cpMissing: number; needDay: number; needWeek: number; inStore: number; month: string; monthDays: number; daysLeft: number; needMonth: number; needLeft: number; shortMonth: number;
    days: number | null; unsplit: number; feeds: Array<{ name: string; needDay: number; stock: number; days: number | null; shortWeek: number; needMonth: number; shortMonth: number }> };
type Requirement = { supply: Supply; tracking: boolean; kgDay: number; cost: number; kgMonth: number; costMonth: number; feeds: Array<{ name: string; kg: number }>; planned: number; milk: number; missing: number; sheds: ReqShed[] };
type HealthLinks = { checkupsDue: number; openReports: number; canCheck: boolean; canCase: boolean; canCare: boolean };
type Props = { health: HealthLinks | null; shedBoard: ShedBoardData | null; canMove: boolean; requirement: Requirement | null; actions: ActionSummary[]; feeding: Feeding | null; herd: Array<{ key: string; label: string; value: number }>;
    stats: Record<string, number>;
    attention: Array<GoatRef & { title: string | null; on: string | null }>;
    missing: Array<GoatRef & { missing: string[]; source: string }>;
    kiddings: Array<{ goatId: string; number: string; name: string | null; on: string; days: number }>;
    activity: Array<{ id: number; actor: string; role: string; action: string; url: string | null; ago: string }>;
    breeds: Array<{ name: string; value: number }>;
    growth: Array<{ month: string; kg: number }>;
    finance: Array<{ month: string; in: number; out: number }> | null;
};

type GoatRef = { goatId: string; number: string; name: string | null; coat: string; sex: 'male' | 'female'; health: Health; flag: string | null };

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;
const FLAG: Record<string, string> = { monitor: 'On watch', sick: 'Sick', quarantine: 'In quarantine', recovering: 'Recovering' };

/** D-071: lists show 5 items, then "Show more". */
function ShowMore<T>({ items, render, empty, limit = 5 }: { items: T[]; render: (item: T) => ReactNode; empty?: ReactNode; limit?: number }) {
    const [open, setOpen] = useState(false);
    if (!items.length) return <>{empty ?? null}</>;
    const shown = open ? items : items.slice(0, limit);
    return (
        <>
            {shown.map(render)}
            {items.length > limit ? (
                <li className="list-none">
                    <button type="button" onClick={() => setOpen(!open)} className="flex w-full items-center justify-center gap-1 px-4 py-2 text-sm font-semibold text-cobalt-800 hover:bg-glaze">
                        <ChevronDown size={15} className={open ? 'rotate-180' : ''} aria-hidden />{open ? 'Show less' : `Show ${items.length - limit} more`}
                    </button>
                </li>
            ) : null}
        </>
    );
}

/** A dashboard number that opens the goats (or page) it counts. */
function StatLink({ href, ...stat }: { href: string } & Parameters<typeof Stat>[0]) {
    return <Link href={href} className="group relative block min-w-0 transition-colors hover:bg-glaze"><Stat {...stat} /><ChevronRight size={15} className="absolute right-2 top-3 text-ink-faint opacity-0 transition-opacity group-hover:opacity-100" aria-hidden /></Link>;
}

/**
 * D-070: the dashboard shows what needs a decision today. Headline numbers use the same rules as the categories,
 * empty boxes and charts with too little data are left out, and the per-goat herd wall lives on the Sheds page.
 */
export default function Dashboard({ health, shedBoard, canMove, requirement, actions, stats, feeding, herd, attention, missing, kiddings, activity, breeds, growth, finance }: Props) {
    const { auth } = usePage<SharedProps>().props;
    // 30 s keeps the dashboard current without keeping the gun's processor busy.
    usePoll(LIVE_MS, { only: ['shedBoard', 'requirement', 'actions', 'stats', 'herd', 'feeding', 'attention', 'missing', 'kiddings', 'activity'] });

    const growthChart = useMemo(() => (growth.length >= 2 ? {
        tooltip: { trigger: 'axis', valueFormatter: (v: number) => `${v} KG` },
        xAxis: axis.category(growth.map((g) => monthLabel(g.month))),
        yAxis: { ...axis.value((v) => `${v} KG`), min: 0 }, // D-105: weight graphs start at 0 KG
        series: [{ type: 'line', data: growth.map((g) => g.kg), smooth: true, symbolSize: 6, lineStyle: { width: 2.5 }, areaStyle: { opacity: 0.08 } }],
    } : null), [growth]);

    const breedChart = useMemo(() => {
        if (breeds.length < 2) return null;
        const rows = [...breeds].reverse();
        return {
            tooltip: { trigger: 'axis', axisPointer: { type: 'none' } },
            grid: { left: 4, right: 30, top: 4, bottom: 4, containLabel: true },
            xAxis: { type: 'value', show: false },
            yAxis: { ...axis.category(rows.map((b) => b.name)), axisLine: { show: false } },
            series: [{ type: 'bar', barMaxWidth: 16, barCategoryGap: '35%', data: rows.map((b) => b.value), itemStyle: { color: '#1d5c2e', borderRadius: [0, 3, 3, 0] }, label: { show: true, position: 'right', color: '#163d22', fontWeight: 600 } }],
        };
    }, [breeds]);

    const financeChart = useMemo(() => (finance && finance.length ? {
        tooltip: { trigger: 'axis', valueFormatter: (v: number) => pkrShort(v) },
        legend: { bottom: 0, itemWidth: 12, itemHeight: 10 },
        grid: { left: 8, right: 12, top: 14, bottom: 30, containLabel: true },
        xAxis: axis.category(finance.map((f) => monthLabel(f.month))),
        yAxis: axis.value((v) => `${v / 1000}k`),
        series: [
            { name: 'Income', type: 'bar', data: finance.map((f) => f.in), itemStyle: { color: '#1d5c2e', borderRadius: [3, 3, 0, 0] } },
            { name: 'Spending', type: 'bar', data: finance.map((f) => f.out), itemStyle: { color: '#b8892b', borderRadius: [3, 3, 0, 0] } },
        ],
    } : null), [finance]);

    const total = herd.find((h) => h.key === 'total');
    const categories = herd.filter((h) => h.key !== 'total' && h.value > 0);
    const charts = [growthChart, breedChart, financeChart].filter(Boolean).length;
    // D-088: on the RFID gun charts wait for a tap; drawing them is the heaviest work on its processor.
    const [showCharts, setShowCharts] = useState(() => !inScannerApp());

    return (
        <>
            <Head title="Dashboard" />
            <div className="max-sm:hidden"><PageHeader title="Herd today" description={`Signed in as ${auth?.roleLabel}. Updates by itself every ${LIVE_MS / 1000} seconds.`} /></div>

            <ActionBanner actions={actions} />
            {health ? <HealthShortcuts h={health} /> : null}

            <StatRow>
                <StatLink href="/goats" label="Goats on farm" value={stats.goats} note={stats.noPen ? `${plural(stats.noPen, 'goat', 'goats')} not in a pen` : `${plural(stats.does, 'female', 'females')}, ${plural(stats.bucks, 'male', 'males')}`} tone={stats.noPen ? 'alert' : undefined} />
                <StatLink href="/goats?show=attention" label="Need attention" value={stats.unwell} note={stats.overdueCare ? `${plural(stats.overdueCare, 'goat', 'goats')} overdue for care` : 'Sick or on watch'} tone={stats.unwell || stats.overdueCare ? 'alert' : undefined} />
                <StatLink href="/goats?show=pregnant" label="Pregnant" value={stats.pregnant} note="Does" />
                {feeding ? <StatLink href="/feeding" label="Meals fed today" value={`${stats.feedingDone}/${stats.feedingTotal}`} note={stats.feedingTotal ? 'Pen meals due so far' : 'None due yet'} tone={stats.feedingTotal && stats.feedingDone < stats.feedingTotal ? 'alert' : undefined} /> : null}
                <StatLink href="/tasks" label="Tasks today" value={`${stats.tasksDone}/${stats.tasksTotal}`} note="Done" />
            </StatRow>

            {/* CEO categories (D-046): only the categories that have goats. */}
            {total && total.value ? (
                <Panel title="Herd by category" description={`${plural(total.value, 'goat', 'goats')} on the farm`} className="mt-4" actions={<Link href="/goats" className="text-sm font-semibold text-turq-800 hover:underline">All goats</Link>}>
                    {categories.length ? (
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                            {categories.map((h) => (
                                <Link key={h.key} href={`/goats?show=${h.key}`} className={`rounded-2xl p-3 ring-cobalt-200 transition hover:ring-2 ${(h.key === 'sick' || h.key === 'treatment') ? 'bg-crit-100' : 'bg-glaze'}`}>
                                    <div className="text-2xl font-bold tabular-nums text-cobalt-900">{h.value}</div>
                                    <div className="text-xs font-semibold leading-tight text-ink-soft">{h.label}</div>
                                </Link>
                            ))}
                        </div>
                    ) : <p className="text-sm text-ink-soft">Classify goats on their record to see them by category.</p>}
                </Panel>
            ) : <div className="mt-4"><Empty title="No goats yet">Scan a tag or open the Goat Register to add the first goat.</Empty></div>}

            {shedBoard ? <ShedBoard board={shedBoard} canMove={canMove} /> : null}

            <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
                {feeding ? <FeedingToday feeding={feeding} /> : <div />}
                <Panel title="Needs attention" description="Goats not marked Healthy" flush actions={<Link href="/health" className="text-sm font-semibold text-turq-800 hover:underline">Health board</Link>}>
                    <ul className="divide-y divide-rule">
                        <ShowMore items={attention} empty={<li className="list-none"><Empty title="Every goat is marked Healthy" /></li>} render={(item) => (
                            <li key={item.goatId} className="flex items-center gap-2 px-4 py-2">
                                <div className="min-w-0 flex-1">
                                    <GoatLabel goat={{ id: item.goatId, number: item.number, name: item.name, coat: item.coat, sex: item.sex }} size={34} />
                                    <p className="mt-0.5 truncate text-sm text-ink-soft">{item.title ? `${item.title} · ${item.on}` : 'No open report'}</p>
                                </div>
                                <span className="rounded-[4px] bg-watch-100 px-1.5 py-0.5 text-xs font-semibold text-watch-700">{FLAG[item.flag ?? ''] ?? 'Check'}</span>
                            </li>
                        )} />
                    </ul>
                    {stats.toReview ? <Link href="/health" className="block border-t border-rule px-4 py-2.5 text-sm font-semibold text-cobalt-800 hover:bg-glaze">{plural(stats.toReview, 'open health report', 'open health reports')} on healthy goats to review →</Link> : null}
                </Panel>
            </div>

            {requirement ? <FeedRequirement req={requirement} /> : null}

            {/* D-072: charts, activity and kiddings share one two-column grid, so no panel stretches alone across a wide screen. */}
            {missing.length || charts || activity.length || kiddings.length ? (
                <div className="mt-4 grid items-start gap-4 lg:grid-cols-2 lg:[&>*:last-child:nth-child(odd)]:col-span-2">
                    {missing.length ? (
                <Panel title="Missing data" description="No pen, no weight or a wrong birth date · where each came from" flush
                    actions={<Link href="/goats?show=missing" className="text-sm font-semibold text-turq-800 hover:underline">Open as table</Link>}>
                    <ul className="divide-y divide-rule">
                        <ShowMore items={missing} render={(m) => (
                            <li key={m.goatId} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5">
                                <div className="min-w-0 flex-1">
                                    <GoatLabel goat={{ id: m.goatId, number: m.number, name: m.name, coat: m.coat, sex: m.sex }} size={34} />
                                    <p className="mt-0.5 text-xs text-ink-soft">From: {m.source}</p>
                                </div>
                                <div className="flex flex-wrap gap-1">{m.missing.map((x) => <span key={x} className="rounded-[4px] bg-crit-100 px-1.5 py-0.5 text-xs font-semibold text-crit-700">{x}</span>)}</div>
                                <Link href={`/goats/${m.goatId}`} className="text-sm font-semibold text-cobalt-800 hover:underline">Fix</Link>
                            </li>
                        )} />
                    </ul>
                </Panel>
                    ) : null}
                    {charts && !showCharts ? <Panel title="Charts" description="Average weight, herd by breed, income and spending"><button type="button" onClick={() => setShowCharts(true)} className="w-full rounded-tile border border-rule py-2.5 text-sm font-semibold text-cobalt-800 hover:bg-glaze">Show charts</button></Panel> : null}
                    {growthChart && showCharts ? <Panel title="Average weight" description="All weighings, by month"><Chart option={growthChart} label="Average herd weight by month" height={220} onSelect={() => router.visit('/weights')} /></Panel> : null}
                    {breedChart && showCharts ? <Panel title="Herd by breed" description="Goats on the farm · tap a bar to see them"><Chart option={breedChart} label="Goats by breed" height={Math.max(110, breeds.length * 36 + 24)} onSelect={(name) => router.visit(`/goats?breed=${encodeURIComponent(name)}`)} /></Panel> : null}
                    {financeChart && showCharts ? <Panel title="Income and spending" description="Last 6 months"><Chart option={financeChart} label="Monthly income and spending" height={220} onSelect={() => router.visit('/finance')} /></Panel> : null}
                    {kiddings.length ? (
                        <Panel title="Next kiddings" flush description="Mating date + gestation days">
                            <ul className="divide-y divide-rule">
                                <ShowMore items={kiddings} render={(k) => (
                                    <li key={k.goatId}>
                                        <Link href={`/goats/${k.goatId}`} className="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-glaze">
                                            <span className="min-w-0 truncate font-semibold text-cobalt-800">{k.name ?? k.number}</span>
                                            <span className="shrink-0 text-ink-soft">{k.on} · {k.days < 0 ? `${-k.days}d over` : `${k.days}d`}</span>
                                        </Link>
                                    </li>
                                )} />
                            </ul>
                        </Panel>
                    ) : null}
                    {activity.length ? <Panel title="Recent activity" flush><ActivityList activity={activity} /></Panel> : null}
                </div>
            ) : null}
        </>
    );
}

function ActivityList({ activity }: { activity: Props['activity'] }) {
    return (
        <ul className="divide-y divide-rule">
            <ShowMore items={activity} render={(a) => {
                const body = (
                    <>
                        <span className="min-w-0"><span className="font-semibold text-cobalt-900">{a.actor}</span> {a.action}</span>
                        <span className="shrink-0 text-ink-faint">{a.ago}</span>
                    </>
                );
                return (
                    <li key={a.id}>
                        {a.url ? <Link href={a.url} className="flex items-baseline justify-between gap-3 px-4 py-2 text-sm hover:bg-glaze">{body}</Link> : <div className="flex items-baseline justify-between gap-3 px-4 py-2 text-sm">{body}</div>}
                    </li>
                );
            }} />
        </ul>
    );
}

const FEED_STATUS: Record<FeedStatus, { label: string; className: string }> = {
    done: { label: 'Fed', className: 'bg-ok-100 text-ok-700' },
    late: { label: 'Late', className: 'bg-watch-100 text-watch-700' },
    missed: { label: 'Missed', className: 'bg-crit-100 text-crit-700' },
    due: { label: 'Due', className: 'bg-cobalt-100 text-cobalt-800' },
    upcoming: { label: 'Later', className: 'bg-glaze text-ink-soft' },
    none: { label: '—', className: 'bg-glaze text-ink-faint' },
};

/** D-069: today's feeding on the dashboard — only pens that have goats, each meal's status and KG given of planned. */
/** D-097: one tap from the dashboard to the vet's work: checkups, cases, concerns, preventive care. */
function HealthShortcuts({ h }: { h: HealthLinks }) {
    const item = (href: string, label: string, note: string | null, tone: string) => (
        <Link href={href} className={`kk-lift flex min-h-14 items-center gap-2 rounded-tile border px-3 py-2 ${tone}`}>
            <span className="grid size-7 shrink-0 place-items-center rounded-full bg-white/70 text-lg font-bold leading-none">+</span>
            <span className="min-w-0"><span className="block text-sm font-semibold">{titleCase(label)}</span>{note ? <span className="block truncate text-xs opacity-80">{note}</span> : null}</span>
        </Link>
    );
    return (
        <div className="mb-4 grid grid-cols-2 gap-2 lg:grid-cols-4">
            {h.canCheck ? item('/health?tab=checkups', 'Health checkup', h.checkupsDue ? `${h.checkupsDue} due` : 'All up to date', h.checkupsDue ? 'border-watch-400 bg-watch-100 text-watch-700' : 'border-ok-700/30 bg-ok-100 text-ok-700') : null}
            {h.canCase || h.canCheck ? item('/health?new=entry', 'New health entry', 'Checkup, pregnancy, disease', 'border-rule bg-tile text-cobalt-900') : null}
            {item('/health?new=report', 'Report concern', h.openReports ? `${h.openReports} open` : null, 'border-rule bg-tile text-cobalt-900')}
            {h.canCare ? item('/health?new=care', 'Preventive care', 'Vaccine, deworming', 'border-rule bg-tile text-cobalt-900') : null}
        </div>
    );
}

/** D-086: given against plan, in words and colour. */
function BalanceTag({ balance, diff, className = '' }: { balance: Balance; diff: number; className?: string }) {
    if (balance !== 'over' && balance !== 'short') return null;
    const kgText = `${Math.abs(Math.round(diff * 100) / 100)} KG`;
    return <span className={`inline-block rounded-[4px] px-1.5 py-0.5 text-xs font-semibold ${balance === 'over' ? 'bg-crit-100 text-crit-700' : 'bg-watch-100 text-watch-700'} ${className}`}>{balance === 'over' ? `Over by ${kgText}` : `Short ${kgText}`}</span>;
}

function FeedingToday({ feeding }: { feeding: Feeding }) {
    const meals = Object.keys(feeding.meals) as Meal[];
    const kg = (n: number) => `${Math.round(n * 10) / 10} KG`;
    const [all, setAll] = useState(false);
    const pens = all ? feeding.pens : feeding.pens.slice(0, 5);

    return (
        <Panel title="Feeding today" description={`${kg(feeding.givenKg)} given of ${kg(feeding.plannedKg)} planned · PKR ${feeding.cost.toLocaleString('en-PK')}`}
            actions={<Link href="/feeding" className="text-sm font-semibold text-turq-800 hover:underline">Feed now</Link>}>
            {feeding.lowStock.length ? <p className="mb-3 rounded-tile bg-watch-100 px-3 py-2 text-sm text-watch-700">Low or out of stock: {feeding.lowStock.join(', ')}</p> : null}
            {feeding.overPens ? <p className="mb-3 rounded-tile bg-crit-100 px-3 py-2 text-sm font-semibold text-crit-700">{plural(feeding.overPens, 'pen was', 'pens were')} given more feed today than its goats need now. Plans follow the goats, so a move after feeding shows here.</p> : null}
            {feeding.pens.length ? (
                <>
                {/* Phones and the gun: one row per pen with its three meals side by side. */}
                <ul className="divide-y divide-rule sm:hidden">
                    {pens.map((p) => (
                        <li key={p.id} className="py-2.5">
                            <div className="flex items-baseline justify-between gap-2">
                                <Link href="/feeding" className="font-mono font-semibold text-cobalt-900">{p.name}</Link>
                                <span className="text-xs text-ink-soft">{p.goats} goats{p.onMilk ? ` · ${p.onMilk} on milk` : ''} · {kg(p.dayGiven)} / {kg(p.dayPlanned)} today</span>
                            </div>
                            <div className="mt-1.5 grid grid-cols-3 gap-1.5">
                                {meals.map((m) => {
                                    const x = p.meals[m];
                                    return (
                                        <div key={m} className={`rounded-tile px-2 py-1.5 ${m === feeding.meal ? 'ring-1 ring-cobalt-200' : ''} ${FEED_STATUS[x.status].className}`}>
                                            <div className="text-[11px] font-semibold">{feeding.meals[m]} · {FEED_STATUS[x.status].label}</div>
                                            <div className="text-[11px] opacity-80">{x.planned || x.given ? `${kg(x.given)} / ${kg(x.planned)}` : '—'}</div>
                                            <BalanceTag balance={x.balance} diff={x.diff} className="mt-0.5 !px-1 !text-[10px]" />
                                        </div>
                                    );
                                })}
                            </div>
                        </li>
                    ))}
                </ul>
                <div className="hidden overflow-x-auto sm:block">
                    <table className="w-full min-w-[34rem] text-sm">
                        <thead className="text-left text-ink-soft">
                            <tr>
                                <th className="py-1.5 pr-3 font-semibold">Pen</th>
                                {meals.map((m) => <th key={m} className={`px-2 py-1.5 font-semibold ${m === feeding.meal ? 'text-cobalt-900' : ''}`}>{feeding.meals[m]} <span className="font-normal text-ink-faint">{feeding.times[m]}</span></th>)}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-rule">
                            {pens.map((p) => (
                                <tr key={p.id}>
                                    <td className="py-2 pr-3">
                                        <Link href="/feeding" className="font-mono font-semibold text-cobalt-900 hover:underline">{p.name}</Link>
                                        <div className="text-xs text-ink-soft">{p.goats} goats{p.onMilk ? ` · ${p.onMilk} on milk` : ''} · {kg(p.dayGiven)} / {kg(p.dayPlanned)} today</div>
                                    </td>
                                    {meals.map((m) => {
                                        const x = p.meals[m];
                                        return (
                                            <td key={m} className="px-2 py-2">
                                                <span className={`inline-block rounded-[4px] px-1.5 py-0.5 text-xs font-semibold ${FEED_STATUS[x.status].className}`}>{FEED_STATUS[x.status].label}</span>
                                                {x.planned || x.given ? <div className="mt-0.5 text-xs text-ink-soft">{kg(x.given)} / {kg(x.planned)}</div> : null}
                                                <BalanceTag balance={x.balance} diff={x.diff} className="mt-0.5" />
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {feeding.pens.length > 5 ? (
                    <button type="button" onClick={() => setAll(!all)} className="mt-1 flex w-full items-center justify-center gap-1 rounded-tile py-2 text-sm font-semibold text-cobalt-800 hover:bg-glaze">
                        <ChevronDown size={15} className={all ? 'rotate-180' : ''} aria-hidden />{all ? 'Show less' : `Show ${feeding.pens.length - 5} more pens`}
                    </button>
                ) : null}
                </>
            ) : <p className="text-sm text-ink-soft">No goats in any pen yet.</p>}
        </Panel>
    );
}

/** D-077: the Action Center in one line: how many things need action, the first few, one tap to the list. */
function ActionBanner({ actions }: { actions: ActionSummary[] }) {
    const urgent = actions.filter((a) => a.severity !== 'info');
    const critical = actions.some((a) => a.severity === 'critical');
    if (!urgent.length) {
        return <Link href="/actions" className="mb-4 flex items-center gap-2 rounded-tile border border-ok-700/30 bg-ok-100 px-4 py-2.5 text-sm font-semibold text-ok-700">Nothing needs action right now <span className="ml-auto font-normal underline">Action Center</span></Link>;
    }
    return (
        <Link href="/actions" className={`mb-4 flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-tile border px-4 py-3 transition-colors ${critical ? 'border-crit-500/40 bg-crit-100 hover:bg-crit-100/70' : 'border-watch-400/60 bg-watch-100 hover:bg-watch-100/70'}`}>
            <span className={`font-bold ${critical ? 'text-crit-700' : 'text-watch-700'}`}>{urgent.length} {urgent.length === 1 ? 'thing needs' : 'things need'} action today</span>
            <span className="flex flex-wrap gap-1.5">
                {urgent.slice(0, 4).map((a) => <span key={a.key} className="rounded-[4px] bg-tile/80 px-1.5 py-0.5 text-xs font-semibold text-ink">{titleCase(a.label)} · {a.count}</span>)}
                {urgent.length > 4 ? <span className="text-xs text-ink-soft">+{urgent.length - 4} more</span> : null}
            </span>
            <span className="ml-auto flex items-center gap-1 text-sm font-semibold text-cobalt-900">Open Action Center <ChevronRight size={15} aria-hidden /></span>
        </Link>
    );
}

const REQ_STATE: Record<ReqGoat['state'], string> = { planned: '', milk: 'bg-cobalt-100 text-cobalt-800', no_ration: 'bg-crit-100 text-crit-700', no_weight: 'bg-watch-100 text-watch-700' };

/** D-079: what every goat needs to eat today, and where it is: shed → pen → goat, with totals and cost. */
function FeedRequirement({ req }: { req: Requirement }) {
    const [open, setOpen] = useState<number | null | 'none'>(req.sheds.length === 1 ? req.sheds[0].id : 'none');
    return (
        <Panel className="mt-4" title="Feed requirement today" description={`${req.kgDay} KG for ${plural(req.planned, 'goat', 'goats')}${req.cost ? ` · PKR ${req.cost.toLocaleString('en-PK')} a day` : ''} · ${req.kgMonth} KG${req.costMonth ? ` (PKR ${req.costMonth.toLocaleString('en-PK')})` : ''} in ${req.supply.month}${req.milk ? ` · ${req.milk} kids on milk` : ''}${req.missing ? ` · ${req.missing} not planned` : ''}`}
            actions={<Link href="/feeding" className="text-sm font-semibold text-turq-800 hover:underline">Rations</Link>} flush>
            <SupplyStrip s={req.supply} tracking={req.tracking} />
            {req.sheds.length ? (
                <ul className="divide-y divide-rule">
                    {req.sheds.map((s) => {
                        const isOpen = open === s.id;
                        return (
                            <li key={s.id ?? 'none'}>
                                <button type="button" onClick={() => setOpen(isOpen ? 'none' : s.id)} aria-expanded={isOpen} className="flex w-full flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-left hover:bg-glaze">
                                    <span className="min-w-0 flex-1">
                                        <span className="font-mono font-semibold text-cobalt-900">{s.name}</span>
                                        <span className="block text-xs text-ink-soft">{s.typeName ? `${s.typeName} · ` : ''}{plural(s.count, 'goat', 'goats')} · {s.pens.length} pen{s.pens.length === 1 ? '' : 's'}</span>
                                    </span>
                                    <span className="hidden flex-wrap gap-1 md:flex">{s.feeds.slice(0, 3).map((f) => <span key={f.name} className="rounded-[4px] bg-glaze px-1.5 py-0.5 text-xs text-ink-soft">{f.name} {f.kg} KG</span>)}</span>
                                    <span className="text-right"><span className="block font-bold text-cobalt-900">{s.kgDay} KG/day</span><span className="block text-xs text-ink-soft">{req.supply.cpDay !== null ? `CP ${s.cpDay} KG/day · ` : ''}{s.kgMonth} KG this month{s.cost ? ` · PKR ${s.cost.toLocaleString('en-PK')}/day` : ''}</span></span>
                                    <ChevronDown size={16} className={`text-ink-faint transition-transform ${isOpen ? 'rotate-180' : ''}`} aria-hidden />
                                </button>
                                {isOpen ? <div className="space-y-3 bg-glaze/50 px-4 pb-3">{s.pens.map((p) => <ReqPenCard key={p.id ?? 'none'} pen={p} />)}</div> : null}
                            </li>
                        );
                    })}
                </ul>
            ) : <p className="p-4 text-sm text-ink-soft">No goats on the farm yet.</p>}
        </Panel>
    );
}

function ReqPenCard({ pen }: { pen: ReqPen }) {
    const [all, setAll] = useState(false);
    const rows = all ? pen.goats : pen.goats.slice(0, 5);
    return (
        <div className="rounded-tile border border-rule bg-tile">
            <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-rule px-3 py-2">
                <span className="font-mono text-sm font-semibold text-cobalt-900">{pen.name}</span>
                <span className="text-sm text-ink-soft">{plural(pen.count, 'goat', 'goats')}{pen.unplanned ? ` · ${pen.unplanned} not planned` : ''} · <strong className="text-cobalt-900">{pen.kgDay} KG/day</strong>{pen.cpDay ? ` · CP ${pen.cpDay} KG` : ''} · given today {pen.givenToday} KG <BalanceTag balance={pen.balance} diff={pen.diff} /> · {pen.kgMonth} KG this month{pen.cost ? ` · PKR ${pen.cost.toLocaleString('en-PK')}` : ''}</span>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[46rem] text-sm">
                    <thead className="text-left text-xs text-ink-soft"><tr><th className="px-3 py-1.5 font-semibold">Goat</th><th className="px-3 font-semibold">Group</th><th className="px-3 font-semibold">Weight</th><th className="px-3 font-semibold">Rule</th><th className="px-3 font-semibold">Per Day</th><th className="px-3 font-semibold">CP / Day</th><th className="px-3 font-semibold">This Month</th><th className="px-3 font-semibold">Feeds (KG)</th></tr></thead>
                    <tbody className="divide-y divide-rule">
                        {rows.map((g) => (
                            <tr key={g.id}>
                                <td className="px-3 py-1.5"><Link href={`/goats/${g.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{g.number}</Link></td>
                                <td className="px-3 text-ink-soft">{g.group ?? '—'}</td>
                                <td className="px-3">{g.weight ? `${g.weight} KG` : '—'}</td>
                                <td className="px-3">{g.state === 'planned' ? <span className="text-ink-soft">{g.rule}</span> : <span className={`rounded-[4px] px-1.5 py-0.5 text-xs font-semibold ${REQ_STATE[g.state]}`}>{g.rule}</span>}</td>
                                <td className="px-3 font-semibold text-cobalt-900">{g.state === 'planned' ? <>{g.kgDay} KG{g.max > g.min ? <span className="block text-xs font-normal text-ink-faint">{g.min}–{g.max}</span> : null}</> : '—'}</td>
                                <td className="px-3 text-cobalt-900">{g.state === 'planned' && g.cp !== null ? <>{Math.round(g.cpKg * 1000)} g<span className="block text-xs text-ink-faint" title={g.cpStandard ?? undefined}>{g.cp}% CP{g.cpStandard ? ` · ${g.cpStandard}` : ''}</span></> : '—'}</td>
                                <td className="px-3 text-cobalt-900">{g.state === 'planned' ? `${g.kgMonth} KG` : '—'}</td>
                                <td className="px-3 text-xs text-ink-soft">{g.feeds || '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {pen.goats.length > 5 ? <button type="button" onClick={() => setAll(!all)} className="w-full border-t border-rule px-3 py-1.5 text-sm font-semibold text-cobalt-800 hover:bg-glaze">{all ? 'Show less' : `Show ${pen.goats.length - 5} more`}</button> : null}
        </div>
    );
}

/** D-080: what the goats need against what is in the feed store. */
function SupplyStrip({ s, tracking }: { s: Supply; tracking: boolean }) {
    const short = tracking && s.days !== null && s.days < 7;
    const box = (label: string, value: string, note?: string, alert?: boolean) => (
        <div className={`rounded-tile px-3 py-2 ${alert ? 'bg-crit-100' : 'bg-glaze'}`}>
            <div className="text-xs text-ink-soft">{label}</div>
            <div className={`text-lg font-bold tabular-nums ${alert ? 'text-crit-700' : 'text-cobalt-900'}`}>{value}</div>
            {note ? <div className="text-xs text-ink-faint">{note}</div> : null}
        </div>
    );
    return (
        <div className="space-y-2 border-b border-rule px-4 py-3">
            <div className={`grid grid-cols-2 gap-2 sm:grid-cols-3 ${tracking ? 'lg:grid-cols-5' : s.cpDay !== null ? 'lg:grid-cols-4' : ''}`}>
                {box('Needed today', `${s.needDay} KG`)}
                {box('Needed this week', `${s.needWeek} KG`)}
                {box(`Needed in ${s.month}`, `${s.needMonth} KG`, `${s.needLeft} KG for the ${s.daysLeft} day${s.daysLeft === 1 ? '' : 's'} left${tracking && s.shortMonth ? ` · short ${s.shortMonth} KG` : ''}`, tracking && s.shortMonth > 0)}
                {s.cpDay !== null ? box('Crude Protein Today', `${s.cpDay} KG`, `${s.cpMonth} KG in ${s.month}${s.cpMissing ? ` · ${s.cpMissing} goats without a CP %` : ''}`) : null}
                {!tracking ? null : <>
                {box('In the feed store', `${s.inStore} KG`, undefined, s.needDay > 0 && s.inStore === 0)}
                {box('Store lasts', s.days === null ? '—' : s.days === 0 ? 'Not today' : `${s.days} day${s.days === 1 ? '' : 's'}`, short ? `Short ${Math.max(0, Math.round(s.needWeek - s.inStore))} KG for a week` : undefined, short)}
                </>}
            </div>
            {short ? <p className="rounded-tile bg-crit-100 px-3 py-2 text-sm font-semibold text-crit-700">{s.inStore === 0 ? 'No feed recorded in the store.' : 'Feed is running short.'} The goats need {s.needDay} KG a day: record a purchase in Feeding → Feed store.</p> : null}
            {tracking && s.unsplit > 0 ? <p className="text-xs text-ink-soft">{s.unsplit} KG a day is not split into feeds yet: set a ration (Feeding → Rations) to see which feed is short.</p> : null}
            {tracking && s.feeds.length ? (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[40rem] text-sm">
                        <thead className="text-left text-xs text-ink-soft"><tr><th className="py-1 font-semibold">Feed</th><th className="font-semibold">Needed a Day</th><th className="font-semibold">In Store</th><th className="font-semibold">Lasts</th><th className="font-semibold">Short for a Week</th><th className="font-semibold">Needed in {s.month}</th><th className="font-semibold">Short This Month</th></tr></thead>
                        <tbody className="divide-y divide-rule">{s.feeds.map((f) => <tr key={f.name}><td className="py-1 font-medium">{f.name}</td><td>{f.needDay} KG</td><td>{f.stock} KG</td><td className={f.days !== null && f.days < 7 ? 'font-semibold text-crit-700' : ''}>{f.days === null ? '—' : `${f.days} days`}</td><td className={f.shortWeek ? 'font-semibold text-crit-700' : 'text-ink-faint'}>{f.shortWeek ? `${f.shortWeek} KG` : '—'}</td><td>{f.needMonth} KG</td><td className={f.shortMonth ? 'font-semibold text-crit-700' : 'text-ink-faint'}>{f.shortMonth ? `${f.shortMonth} KG` : '—'}</td></tr>)}</tbody>
                    </table>
                </div>
            ) : null}
        </div>
    );
}
