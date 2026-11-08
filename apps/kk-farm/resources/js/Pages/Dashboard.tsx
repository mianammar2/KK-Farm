import { Head, Link, usePage, usePoll } from '@inertiajs/react';
import { useMemo } from 'react';
import Chart, { axis } from '../components/Chart';
import { Empty, GoatLabel, HealthChip, monthLabel, PageHeader, Panel, pkrShort, Stat, StatRow } from '../components/ui';
import type { Health, SharedProps } from '../types';

type Meal = 'morning' | 'midday' | 'evening';
type FeedStatus = 'done' | 'late' | 'missed' | 'due' | 'upcoming' | 'none';
type Feeding = { meal: Meal; meals: Record<Meal, string>; times: Record<Meal, string>; plannedKg: number; givenKg: number; cost: number; lowStock: string[];
    pens: Array<{ id: number; name: string; goats: number; onMilk: number; meals: Record<Meal, { status: FeedStatus; planned: number; given: number }> }> };
type Props = { feeding: Feeding | null; herd: Array<{ key: string; label: string; value: number }>;
    stats: Record<string, number>;
    attention: Array<{ id: number; goatId: string; number: string; name: string | null; coat: string; sex: 'male' | 'female'; health: Health; title: string; on: string }>;
    kiddings: Array<{ goatId: string; number: string; name: string | null; on: string; days: number }>;
    activity: Array<{ id: number; actor: string; role: string; action: string; url: string | null; ago: string }>;
    breeds: Array<{ name: string; value: number }>;
    growth: Array<{ month: string; kg: number }>;
    finance: Array<{ month: string; in: number; out: number }> | null;
};

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

/**
 * D-070: the dashboard shows what needs a decision today. Headline numbers use the same rules as the categories,
 * empty boxes and charts with too little data are left out, and the per-goat herd wall lives on the Sheds page.
 */
export default function Dashboard({ stats, feeding, herd, attention, kiddings, activity, breeds, growth, finance }: Props) {
    const { auth } = usePage<SharedProps>().props;
    // 30 s keeps the dashboard current without keeping the gun's processor busy.
    usePoll(30000, { only: ['stats', 'herd', 'feeding', 'attention', 'kiddings', 'activity'] });

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
            grid: { left: 8, right: 34, top: 6, bottom: 6, containLabel: true },
            xAxis: { type: 'value', show: false },
            yAxis: { ...axis.category(rows.map((b) => b.name)), axisLine: { show: false } },
            series: [{ type: 'bar', barMaxWidth: 18, data: rows.map((b) => b.value), itemStyle: { color: '#1d5c2e', borderRadius: [0, 3, 3, 0] }, label: { show: true, position: 'right', color: '#163d22', fontWeight: 600 } }],
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
                <Stat label="Goats on farm" value={stats.goats} note={`${plural(stats.does, 'female', 'females')}, ${plural(stats.bucks, 'male', 'males')}`} />
                <Stat label="Need attention" value={stats.unwell} note={stats.overdueCare ? `${plural(stats.overdueCare, 'goat', 'goats')} overdue for care` : 'Sick or on watch'} tone={stats.unwell || stats.overdueCare ? 'alert' : undefined} />
                <Stat label="Pregnant" value={stats.pregnant} note="Does" />
                {feeding ? <Stat label="Meals fed today" value={`${stats.feedingDone}/${stats.feedingTotal}`} note={stats.feedingTotal ? 'Pen meals due so far' : 'None due yet'} tone={stats.feedingTotal && stats.feedingDone < stats.feedingTotal ? 'alert' : undefined} /> : null}
                <Stat label="Tasks today" value={`${stats.tasksDone}/${stats.tasksTotal}`} note="Done" />
            </StatRow>

            {/* CEO categories (D-046): only the categories that have goats. */}
            {total && total.value ? (
                <Panel title="Herd by category" description={`${plural(total.value, 'goat', 'goats')} on the farm`} className="mt-4">
                    {categories.length ? (
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                            {categories.map((h) => (
                                <div key={h.key} className={`rounded-2xl p-3 ${(h.key === 'sick' || h.key === 'treatment') ? 'bg-crit-100' : 'bg-glaze'}`}>
                                    <div className="text-2xl font-bold tabular-nums text-cobalt-900">{h.value}</div>
                                    <div className="text-xs font-semibold leading-tight text-ink-soft">{h.label}</div>
                                </div>
                            ))}
                        </div>
                    ) : <p className="text-sm text-ink-soft">Classify goats on their record to see them by category.</p>}
                </Panel>
            ) : <div className="mt-4"><Empty title="No goats yet">Scan a tag or open the Goat Register to add the first goat.</Empty></div>}

            <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
                {feeding ? <FeedingToday feeding={feeding} /> : <div />}
                <Panel title="Needs attention" flush actions={<Link href="/health" className="text-sm font-semibold text-turq-800 hover:underline">Health board</Link>}>
                    {attention.length ? (
                        <ul className="divide-y divide-rule">
                            {attention.map((item) => (
                                <li key={item.id} className="flex items-center gap-2 px-4 py-2">
                                    <div className="min-w-0 flex-1">
                                        <GoatLabel goat={{ id: item.goatId, number: item.number, name: item.name, coat: item.coat, sex: item.sex }} size={34} />
                                        <p className="mt-0.5 truncate text-sm text-ink-soft">{item.title} · {item.on}</p>
                                    </div>
                                    <HealthChip health={item.health} />
                                </li>
                            ))}
                        </ul>
                    ) : <Empty title="Nothing needs attention" />}
                </Panel>
            </div>

            {charts ? (
                <div className={`mt-4 grid gap-4 ${charts > 1 ? 'lg:grid-cols-2' : ''} ${charts > 2 ? 'xl:grid-cols-3' : ''}`}>
                    {growthChart ? <Panel title="Average weight" description="All weighings, by month"><Chart option={growthChart} label="Average herd weight by month" height={220} /></Panel> : null}
                    {breedChart ? <Panel title="Herd by breed" description="Goats on the farm"><Chart option={breedChart} label="Goats by breed" height={220} /></Panel> : null}
                    {financeChart ? <Panel title="Income and spending" description="Last 6 months"><Chart option={financeChart} label="Monthly income and spending" height={220} /></Panel> : null}
                </div>
            ) : null}

            {activity.length || kiddings.length ? (
                <div className={`mt-4 grid gap-4 ${kiddings.length ? 'xl:grid-cols-[minmax(0,1fr)_21rem]' : ''}`}>
                    {activity.length ? <Panel title="Recent activity" flush><ActivityList activity={activity} /></Panel> : null}
                    {kiddings.length ? (
                        <Panel title="Next kiddings" flush description="Mating date + gestation days">
                            <ul className="divide-y divide-rule">
                                {kiddings.map((k) => (
                                    <li key={k.goatId} className="flex items-center justify-between gap-2 px-4 py-2 text-sm">
                                        <Link href={`/goats/${k.goatId}`} className="min-w-0 truncate font-semibold text-cobalt-800 hover:underline">{k.name ?? k.number}</Link>
                                        <span className="shrink-0 text-ink-soft">{k.on} · {k.days < 0 ? `${-k.days}d over` : `${k.days}d`}</span>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    ) : null}
                </div>
            ) : null}
        </>
    );
}

function ActivityList({ activity }: { activity: Props['activity'] }) {
    return (
        <ul className="divide-y divide-rule">
            {activity.map((a) => (
                <li key={a.id} className="flex items-baseline justify-between gap-3 px-4 py-2 text-sm">
                    <span className="min-w-0">
                        <span className="font-semibold text-cobalt-900">{a.actor}</span>{' '}
                        {a.url ? <Link href={a.url} className="text-ink hover:underline">{a.action}</Link> : <span>{a.action}</span>}
                    </span>
                    <span className="shrink-0 text-ink-faint">{a.ago}</span>
                </li>
            ))}
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

    return (
        <Panel title="Feeding today" description={`${kg(feeding.givenKg)} given of ${kg(feeding.plannedKg)} planned · PKR ${feeding.cost.toLocaleString('en-PK')}`}
            actions={<Link href="/feeding" className="text-sm font-semibold text-turq-800 hover:underline">Feed now</Link>}>
            {feeding.lowStock.length ? <p className="mb-3 rounded-tile bg-watch-100 px-3 py-2 text-sm text-watch-700">Low or out of stock: {feeding.lowStock.join(', ')}</p> : null}
            {feeding.pens.length ? (
                <>
                {/* Phones and the gun: one row per pen with its three meals side by side. */}
                <ul className="divide-y divide-rule sm:hidden">
                    {feeding.pens.map((p) => (
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
                            {feeding.pens.map((p) => (
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
                </>
            ) : <p className="text-sm text-ink-soft">No goats in any pen yet.</p>}
        </Panel>
    );
}
