import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { LIVE_MS } from '../lib/live';
import { inScannerApp } from '../lib/native';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import Chart from '../components/LazyChart';
import { axis } from '../lib/chartTheme';
import { Empty, GoatLabel, monthLabel, PageHeader, Panel, pkrShort, Stat, StatRow } from '../components/ui';
import type { Health, SharedProps } from '../types';
import { titleCase } from '../lib/titleCase';
import { useFive } from '../lib/useFive';
import { BalanceTag, type Balance, type Requirement } from '../components/FeedRequirement';

type Meal = 'morning' | 'midday' | 'evening';
type FeedStatus = 'done' | 'late' | 'missed' | 'due' | 'upcoming' | 'none';
type Feeding = { meal: Meal; meals: Record<Meal, string>; times: Record<Meal, string>; plannedKg: number; givenKg: number; cost: number; lowStock: string[]; overPens: number;
    pens: Array<{ id: number; name: string; goats: number; onMilk: number; dayPlanned: number; dayGiven: number; over: boolean; diff: number; meals: Record<Meal, { status: FeedStatus; planned: number; given: number; balance: Balance; diff: number }> }> };
type ActionSummary = { key: string; label: string; count: number; severity: 'critical' | 'warning' | 'info' };
type HealthLinks = { checkupsDue: number; openReports: number; canCheck: boolean; canCase: boolean; canCare: boolean };
type Props = { pensSummary: PensSummary | null; requirement: FeedSummary | null; feeding: Feeding | null; herd: Array<{ key: string; label: string; value: number }>; herdAge: HerdAge;
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
                        <ChevronDown size={15} className={open ? 'rotate-180' : ''} aria-hidden />{open ? 'Show Less' : `Show ${items.length - limit} More`}
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
export default function Dashboard({ pensSummary, requirement, stats, feeding, herd, herdAge, attention, missing, kiddings, activity, breeds, growth, finance }: Props) {
    const { auth } = usePage<SharedProps>().props;
    // 30 s keeps the dashboard current without keeping the gun's processor busy.
    usePoll(LIVE_MS, { only: ['pensSummary', 'herdAge', 'requirement', 'stats', 'herd', 'feeding', 'attention', 'missing', 'kiddings', 'activity'] });

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
    const charts = [growthChart, breedChart, financeChart].filter(Boolean).length;
    // D-088: on the RFID gun charts wait for a tap; drawing them is the heaviest work on its processor.
    const [showCharts, setShowCharts] = useState(() => !inScannerApp());

    return (
        <>
            <Head title="Dashboard" />
            <div className="max-sm:hidden"><PageHeader title="Herd today" description={`Signed in as ${auth?.roleLabel}. Updates by itself every ${LIVE_MS / 1000} seconds.`} /></div>

            {/* D-121: actions are in the notification bell; health + buttons on Veterinary & Health. */}

            <StatRow>
                {/* D-119: a number that is zero is not shown. */}
                <StatLink href="/goats" label="Goats on farm" value={stats.goats} note={stats.noPen ? `${plural(stats.noPen, 'goat', 'goats')} not in a pen` : [stats.does ? plural(stats.does, 'female', 'females') : null, stats.bucks ? plural(stats.bucks, 'male', 'males') : null].filter(Boolean).join(', ')} tone={stats.noPen ? 'alert' : undefined} />
                {stats.unwell || stats.overdueCare ? <StatLink href="/goats?show=attention" label="Need attention" value={stats.unwell} note={stats.overdueCare ? `${plural(stats.overdueCare, 'goat', 'goats')} overdue for care` : 'Sick or on watch'} tone="alert" /> : null}
                {stats.pregnant ? <StatLink href="/goats?show=pregnant" label="Pregnant" value={stats.pregnant} note="Does" /> : null}
                {feeding ? <StatLink href="/feeding" label="Meals fed today" value={`${stats.feedingDone}/${stats.feedingTotal}`} note={stats.feedingTotal ? 'Pen meals due so far' : 'None due yet'} tone={stats.feedingTotal && stats.feedingDone < stats.feedingTotal ? 'alert' : undefined} /> : null}
                {stats.tasksTotal ? <StatLink href="/tasks" label="Tasks today" value={`${stats.tasksDone}/${stats.tasksTotal}`} note="Done" /> : null}
            </StatRow>

            {/* D-118: the herd by age and gender (and by breed); health is in Needs Attention. */}
            {total && total.value ? <HerdByAge h={herdAge} /> : <div className="mt-4"><Empty title="No goats yet">Scan a tag or open the Goat Register to add the first goat.</Empty></div>}

            {pensSummary && pensSummary.sheds.length ? <PensAtAGlance data={pensSummary} /> : null}

            {feeding ? <div className="mt-4">
                {feeding ? <FeedingToday feeding={feeding} /> : null}
                
            </div> : null}

            {requirement && requirement.sheds.length ? <FeedAtAGlance req={requirement} /> : null}

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

type HerdAge = {
    rows: Array<{ key: string; label: string; range: string; female: number; male: number; total: number }>; female: number; male: number; total: number;
    breeds: Array<{ breed: string; female: number; male: number; total: number }>;
};

/** D-118: Kids, Growers and Adults by gender, from each goat's age today; every number opens those goats. */
function HerdByAge({ h }: { h: HerdAge }) {
    const [view, setView] = useState<'age' | 'breed'>('age');
    const cell = (key: string, n: number, strong = false) => n
        ? <Link href={`/goats?show=${key}`} className={`block rounded-[4px] px-2 py-1 tabular-nums hover:bg-glaze hover:underline ${strong ? 'font-bold text-cobalt-900' : 'font-semibold text-cobalt-800'}`}>{n}</Link>
        : <span className="block px-2 py-1" aria-label="none" />;
    const pct = (n: number) => (h.total ? Math.round((n / h.total) * 100) : 0);
    // D-119: rows and columns with nothing in them are not shown.
    const rows = h.rows.filter((r) => r.total > 0);
    const [breeds, moreBreeds] = useFive(h.breeds);
    const showF = h.female > 0;
    const showM = h.male > 0;
    return (
        <Panel title="Herd by Age and Gender" description={`${plural(h.total, 'goat', 'goats')} on the farm${showF && showM ? ` · ${h.female} female, ${h.male} male` : showF ? ', all female' : ', all male'}`} className="mt-4"
            actions={<div className="flex gap-1" role="group" aria-label="View">{(['age', 'breed'] as const).map((v) => <button key={v} type="button" onClick={() => setView(v)} className={`rounded-tile px-2.5 py-1 text-sm font-semibold ${view === v ? 'bg-cobalt-800 text-white' : 'text-cobalt-800 hover:bg-glaze'}`}>{v === 'age' ? 'By Age' : 'By Breed'}</button>)}</div>}>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[26rem] text-sm">
                    <thead className="text-left text-xs text-ink-soft"><tr><th className="py-1.5 pr-3 font-semibold">{view === 'age' ? 'Age' : 'Breed'}</th>{showF ? <th className="px-2 text-right font-semibold">♀ Female</th> : null}{showM ? <th className="px-2 text-right font-semibold">♂ Male</th> : null}<th className="px-2 text-right font-semibold">Total</th><th className="w-1/3 pl-3 font-semibold">Share</th></tr></thead>
                    <tbody className="divide-y divide-rule">
                        {view === 'age' ? rows.map((r) => (
                            <tr key={r.key}>
                                <td className="py-1.5 pr-3"><span className="font-semibold text-cobalt-900">{r.label}</span><span className="block text-xs text-ink-soft">{r.range}</span></td>
                                {showF ? <td className="text-right">{cell(`${r.key}_female`, r.female)}</td> : null}
                                {showM ? <td className="text-right">{cell(`${r.key}_male`, r.male)}</td> : null}
                                <td className="text-right">{cell(r.key, r.total, true)}</td>
                                <td className="pl-3"><div className="flex h-2.5 overflow-hidden rounded-full bg-glaze" aria-hidden><div className="bg-[#c2185b]/70" style={{ width: `${pct(r.female)}%` }} /><div className="bg-cobalt-700/70" style={{ width: `${pct(r.male)}%` }} /></div><span className="text-xs text-ink-soft">{pct(r.total)}%</span></td>
                            </tr>
                        )) : breeds.map((b) => (
                            <tr key={b.breed}>
                                <td className="py-1.5 pr-3 font-semibold text-cobalt-900">{b.breed}</td>
                                {showF ? <td className="px-2 text-right tabular-nums">{b.female || ''}</td> : null}
                                {showM ? <td className="px-2 text-right tabular-nums">{b.male || ''}</td> : null}
                                <td className="px-2 text-right font-bold tabular-nums text-cobalt-900">{b.total}</td>
                                <td className="pl-3"><div className="flex h-2.5 overflow-hidden rounded-full bg-glaze" aria-hidden><div className="bg-[#c2185b]/70" style={{ width: `${pct(b.female)}%` }} /><div className="bg-cobalt-700/70" style={{ width: `${pct(b.male)}%` }} /></div><span className="text-xs text-ink-soft">{pct(b.total)}%</span></td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot className="border-t-2 border-rule text-sm"><tr><td className="py-1.5 pr-3 font-bold text-cobalt-900">All Goats</td>{showF ? <td className="text-right">{cell('female', h.female, true)}</td> : null}{showM ? <td className="text-right">{cell('male', h.male, true)}</td> : null}<td className="text-right">{cell('total', h.total, true)}</td><td /></tr></tfoot>
                </table>
                {view === 'breed' ? moreBreeds : null}
            </div>
        </Panel>
    );
}

type PensSummary = {
    sheds: Array<{ id: number; name: string; typeName: string | null; goats: number; pens: Array<{ id: number; name: string; code: string; capacity: number | null; goats: number; female: number; male: number; attention: number }> }>;
    noPen: number;
};

/** D-120: sheds, then their pens with how many goats; a pen opens its goats. Moving goats is on the Sheds page. */
function PensAtAGlance({ data }: { data: PensSummary }) {
    const [sheds, moreSheds] = useFive(data.sheds);
    return (
        <Panel title="Sheds and Pens" description={`${plural(data.sheds.reduce((n, s) => n + s.goats, 0), 'goat', 'goats')} in ${plural(data.sheds.reduce((n, s) => n + s.pens.length, 0), 'pen', 'pens')}${data.noPen ? ` · ${data.noPen} not in a pen` : ''}`} className="mt-4" flush
            actions={<Link href="/sheds" className="text-sm font-semibold text-turq-800 hover:underline">Move Goats</Link>}>
            <ul className="divide-y divide-rule">{sheds.map((s) => <ShedRow key={s.id} shed={s} />)}</ul>
            {moreSheds}
            {data.noPen ? <Link href="/goats?show=no_pen" className="block border-t border-rule px-4 py-2.5 text-sm font-semibold text-crit-700 hover:bg-glaze">{plural(data.noPen, 'goat', 'goats')} not in a pen →</Link> : null}
        </Panel>
    );
}

function ShedRow({ shed }: { shed: PensSummary['sheds'][number] }) {
    const [pens, more] = useFive(shed.pens);
    return (
        <li className="px-4 py-3">
            <div className="mb-2 flex flex-wrap items-baseline gap-x-2"><span className="font-mono font-semibold text-cobalt-900">{shed.name}</span>{shed.typeName ? <span className="text-xs text-ink-soft">{shed.typeName}</span> : null}<span className="ml-auto text-sm font-semibold text-cobalt-900">{plural(shed.goats, 'goat', 'goats')}</span></div>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                {pens.map((p) => {
                    const full = p.capacity !== null && p.goats > p.capacity;
                    return (
                        <Link key={p.id} href={`/goats?pen=${encodeURIComponent(p.name)}`} className="kk-lift rounded-tile border border-rule bg-glaze/60 px-3 py-2 hover:border-cobalt-700">
                            <span className="block truncate font-mono text-xs font-semibold text-ink-soft" title={p.name}>{p.name}</span>
                            <span className={`block text-2xl font-bold tabular-nums ${full ? 'text-crit-700' : 'text-cobalt-900'}`}>{p.goats}{p.capacity !== null ? <span className="text-sm font-semibold text-ink-soft">/{p.capacity}</span> : null}</span>
                            <span className="block text-xs text-ink-soft">{[p.female ? `♀ ${p.female}` : null, p.male ? `♂ ${p.male}` : null, p.attention ? `${p.attention} need attention` : null].filter(Boolean).join(' · ')}</span>
                        </Link>
                    );
                })}
            </div>
            {more ? <div className="mt-2 -mx-4">{more}</div> : null}
        </li>
    );
}

type FeedSummary = Omit<Requirement, 'sheds' | 'feeds'> & {
    sheds: Array<{ id: number | null; name: string; typeName: string | null; kgDay: number; cpDay: number; count: number;
        pens: Array<{ id: number | null; name: string; count: number; kgDay: number; cpDay: number; unplanned: number; balance: Balance; diff: number }> }>;
};

/** D-121: feed needed today like Sheds and Pens: totals, then sheds and pen cards; a pen opens its goats on Feeding → Requirement. */
function FeedAtAGlance({ req }: { req: FeedSummary }) {
    const [sheds, moreSheds] = useFive(req.sheds);
    const s = req.supply;
    const pens = req.sheds.reduce((n, x) => n + x.pens.length, 0);
    const box = (label: string, value: string, note?: string) => (
        <div className="rounded-tile bg-glaze px-3 py-2"><div className="text-xs text-ink-soft">{label}</div><div className="text-lg font-bold tabular-nums text-cobalt-900">{value}</div>{note ? <div className="text-xs text-ink-faint">{note}</div> : null}</div>
    );
    return (
        <Panel className="mt-4" flush title="Feed Requirement Today" description={`${plural(req.planned, 'goat', 'goats')} in ${plural(pens, 'pen', 'pens')}${req.milk ? ` · ${req.milk} kids on milk` : ''}${req.missing ? ` · ${req.missing} not planned` : ''}`}
            actions={<Link href="/feeding?tab=requirement" className="text-sm font-semibold text-turq-800 hover:underline">Each Goat</Link>}>
            <div className={`grid grid-cols-2 gap-2 border-b border-rule px-4 py-3 sm:grid-cols-3 ${s.cpDay !== null ? 'lg:grid-cols-4' : ''}`}>
                {box('Needed Today', `${s.needDay} KG`, req.cost ? `PKR ${req.cost.toLocaleString('en-PK')}` : undefined)}
                {box('Needed This Week', `${s.needWeek} KG`)}
                {box(`Needed in ${s.month}`, `${s.needMonth} KG`, `${s.needLeft} KG for the ${s.daysLeft} days left`)}
                {s.cpDay !== null ? box('Crude Protein Today', `${s.cpDay} KG`, `${s.cpMonth} KG in ${s.month}`) : null}
            </div>
            <ul className="divide-y divide-rule">
                {sheds.map((sh) => <FeedShedRow key={sh.id ?? 'none'} shed={sh} tracking={req.tracking} />)}
            </ul>
            {moreSheds}
        </Panel>
    );
}

function FeedShedRow({ shed, tracking }: { shed: FeedSummary['sheds'][number]; tracking: boolean }) {
    const [pens, more] = useFive(shed.pens);
    return (
        <li className="px-4 py-3">
            <div className="mb-2 flex flex-wrap items-baseline gap-x-2"><span className="font-mono font-semibold text-cobalt-900">{shed.name}</span>{shed.typeName ? <span className="text-xs text-ink-soft">{shed.typeName}</span> : null}
                <span className="ml-auto text-sm font-semibold text-cobalt-900">{shed.kgDay} KG/day{shed.cpDay ? <span className="font-normal text-ink-soft"> · CP {shed.cpDay} KG</span> : null}</span></div>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                {pens.map((p) => (
                    <Link key={p.id ?? 'none'} href={p.id ? `/feeding?tab=requirement&pen=${p.id}` : '/feeding?tab=requirement'} className="kk-lift rounded-tile border border-rule bg-glaze/60 px-3 py-2 hover:border-cobalt-700">
                        <span className="block truncate font-mono text-xs font-semibold text-ink-soft" title={p.name}>{p.name}</span>
                        <span className="block text-2xl font-bold tabular-nums text-cobalt-900">{p.kgDay}<span className="text-sm font-semibold text-ink-soft"> KG/day</span></span>
                        <span className="block text-xs text-ink-soft">{[plural(p.count, 'goat', 'goats'), p.cpDay ? `CP ${Math.round(p.cpDay * 1000).toLocaleString('en-PK')} g` : null, p.unplanned ? `${p.unplanned} not planned` : null].filter(Boolean).join(' · ')}</span>
                        {tracking ? <BalanceTag balance={p.balance} diff={p.diff} className="mt-1" /> : null}
                    </Link>
                ))}
            </div>
            {more ? <div className="mt-2 -mx-4">{more}</div> : null}
        </li>
    );
}
