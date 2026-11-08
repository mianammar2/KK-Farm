import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import Chart, { axis } from '../components/Chart';
import { Empty, GoatLabel, monthLabel, PageHeader, Panel, pkrShort, Stat, StatRow } from '../components/ui';
import type { Health, SharedProps } from '../types';

type Meal = 'morning' | 'midday' | 'evening';
type FeedStatus = 'done' | 'late' | 'missed' | 'due' | 'upcoming' | 'none';
type Feeding = { meal: Meal; meals: Record<Meal, string>; times: Record<Meal, string>; plannedKg: number; givenKg: number; cost: number; lowStock: string[];
    pens: Array<{ id: number; name: string; goats: number; onMilk: number; meals: Record<Meal, { status: FeedStatus; planned: number; given: number }> }> };
type Props = { feeding: Feeding | null; herd: Array<{ key: string; label: string; value: number }>;
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
export default function Dashboard({ stats, feeding, herd, attention, missing, kiddings, activity, breeds, growth, finance }: Props) {
    const { auth } = usePage<SharedProps>().props;
    // 30 s keeps the dashboard current without keeping the gun's processor busy.
    usePoll(30000, { only: ['stats', 'herd', 'feeding', 'attention', 'missing', 'kiddings', 'activity'] });

    const growthChart = useMemo(() => (growth.length >= 2 ? {
        tooltip: { trigger: 'axis', valueFormatter: (v: number) => `${v} kg` },
        xAxis: axis.category(growth.map((g) => monthLabel(g.month))),
        yAxis: { ...axis.value((v) => `${v} kg`), scale: true },
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

    return (
        <>
            <Head title="Dashboard" />
            <PageHeader title="Herd today" description={`Signed in as ${auth?.roleLabel}. Updates by itself every 30 seconds.`} />

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

            {/* D-072: charts, activity and kiddings share one two-column grid, so no panel stretches alone across a wide screen. */}
            {missing.length || charts || activity.length || kiddings.length ? (
                <div className="mt-4 grid items-start gap-4 lg:grid-cols-2 lg:[&>*:last-child:nth-child(odd)]:col-span-2">
                    {missing.length ? (
                <Panel title="Missing data" description="No pen or weight yet · where each came from" flush
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
                    {growthChart ? <Panel title="Average weight" description="All weighings, by month"><Chart option={growthChart} label="Average herd weight by month" height={220} onSelect={() => router.visit('/weights')} /></Panel> : null}
                    {breedChart ? <Panel title="Herd by breed" description="Goats on the farm · tap a bar to see them"><Chart option={breedChart} label="Goats by breed" height={Math.max(110, breeds.length * 36 + 24)} onSelect={(name) => router.visit(`/goats?breed=${encodeURIComponent(name)}`)} /></Panel> : null}
                    {financeChart ? <Panel title="Income and spending" description="Last 6 months"><Chart option={financeChart} label="Monthly income and spending" height={220} onSelect={() => router.visit('/finance')} /></Panel> : null}
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

/** D-069: today's feeding on the dashboard — only pens that have goats, each meal's status and kg given of planned. */
function FeedingToday({ feeding }: { feeding: Feeding }) {
    const meals = Object.keys(feeding.meals) as Meal[];
    const kg = (n: number) => `${Math.round(n * 10) / 10} kg`;
    const [all, setAll] = useState(false);
    const pens = all ? feeding.pens : feeding.pens.slice(0, 5);

    return (
        <Panel title="Feeding today" description={`${kg(feeding.givenKg)} given of ${kg(feeding.plannedKg)} planned · PKR ${feeding.cost.toLocaleString('en-PK')}`}
            actions={<Link href="/feeding" className="text-sm font-semibold text-turq-800 hover:underline">Feed now</Link>}>
            {feeding.lowStock.length ? <p className="mb-3 rounded-tile bg-watch-100 px-3 py-2 text-sm text-watch-700">Low or out of stock: {feeding.lowStock.join(', ')}</p> : null}
            {feeding.pens.length ? (
                <>
                {/* Phones and the gun: one row per pen with its three meals side by side. */}
                <ul className="divide-y divide-rule sm:hidden">
                    {pens.map((p) => (
                        <li key={p.id} className="py-2.5">
                            <div className="flex items-baseline justify-between gap-2">
                                <Link href="/feeding" className="font-mono font-semibold text-cobalt-900">{p.name}</Link>
                                <span className="text-xs text-ink-soft">{p.goats} goats{p.onMilk ? ` · ${p.onMilk} on milk` : ''}</span>
                            </div>
                            <div className="mt-1.5 grid grid-cols-3 gap-1.5">
                                {meals.map((m) => {
                                    const x = p.meals[m];
                                    return (
                                        <div key={m} className={`rounded-tile px-2 py-1.5 ${m === feeding.meal ? 'ring-1 ring-cobalt-200' : ''} ${FEED_STATUS[x.status].className}`}>
                                            <div className="text-[11px] font-semibold">{feeding.meals[m]} · {FEED_STATUS[x.status].label}</div>
                                            <div className="text-[11px] opacity-80">{x.planned || x.given ? `${kg(x.given)} / ${kg(x.planned)}` : '—'}</div>
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
                                        <div className="text-xs text-ink-soft">{p.goats} goats{p.onMilk ? ` · ${p.onMilk} on milk` : ''}</div>
                                    </td>
                                    {meals.map((m) => {
                                        const x = p.meals[m];
                                        return (
                                            <td key={m} className="px-2 py-2">
                                                <span className={`inline-block rounded-[4px] px-1.5 py-0.5 text-xs font-semibold ${FEED_STATUS[x.status].className}`}>{FEED_STATUS[x.status].label}</span>
                                                {x.planned || x.given ? <div className="mt-0.5 text-xs text-ink-soft">{kg(x.given)} / {kg(x.planned)}</div> : null}
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
