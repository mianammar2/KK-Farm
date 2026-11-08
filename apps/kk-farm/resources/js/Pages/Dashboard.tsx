import { Head, Link, usePage, usePoll } from '@inertiajs/react';
import { useMemo } from 'react';
import Chart, { axis } from '../components/Chart';
import HerdWall, { type WallPen } from '../components/HerdWall';
import { motion } from 'motion/react';
import { Empty, GoatLabel, HealthChip, monthLabel, PageHeader, Panel, pkrShort, Stat, StatRow } from '../components/ui';
import type { Health, SharedProps } from '../types';

type Meal = 'morning' | 'midday' | 'evening';
type FeedStatus = 'done' | 'late' | 'missed' | 'due' | 'upcoming' | 'none';
type Feeding = { meal: Meal; meals: Record<Meal, string>; times: Record<Meal, string>; plannedKg: number; givenKg: number; cost: number; lowStock: string[];
    pens: Array<{ id: number; name: string; goats: number; onMilk: number; meals: Record<Meal, { status: FeedStatus; planned: number; given: number }> }> };
type Props = { feeding: Feeding | null; herd: Array<{ key: string; label: string; value: number }>;
    stats: Record<string, number>;
    wall: WallPen[];
    attention: Array<{ id: number; goatId: string; number: string; name: string | null; coat: string; sex: 'male' | 'female'; health: Health; title: string; on: string }>;
    kiddings: Array<{ goatId: string; number: string; name: string | null; on: string; days: number }>;
    activity: Array<{ id: number; actor: string; role: string; action: string; url: string | null; ago: string }>;
    breeds: Array<{ name: string; value: number }>;
    growth: Array<{ month: string; kg: number }>;
    finance: Array<{ month: string; in: number; out: number }> | null;
};

export default function Dashboard({ stats, feeding, herd, wall, attention, kiddings, activity, breeds, growth, finance }: Props) {
    const { auth } = usePage<SharedProps>().props;
    // 30 s keeps the dashboard current without keeping the gun's processor busy.
    usePoll(30000, { only: ['stats', 'herd', 'wall', 'attention', 'activity'] });

    const growthChart = useMemo(() => ({
        tooltip: { trigger: 'axis', valueFormatter: (v: number) => `${v} kg` },
        xAxis: axis.category(growth.map((g) => monthLabel(g.month))),
        yAxis: { ...axis.value((v) => `${v} kg`), scale: true },
        series: [{ type: 'line', data: growth.map((g) => g.kg), smooth: true, symbolSize: 6, lineStyle: { width: 2.5 }, areaStyle: { opacity: 0.08 } }],
    }), [growth]);

    const breedChart = useMemo(() => {
        const rows = [...breeds].reverse();
        return {
            tooltip: { trigger: 'axis', axisPointer: { type: 'none' } },
            grid: { left: 8, right: 34, top: 6, bottom: 6, containLabel: true },
            xAxis: { type: 'value', show: false },
            yAxis: { ...axis.category(rows.map((b) => b.name)), axisLine: { show: false } },
            series: [{ type: 'bar', barMaxWidth: 18, data: rows.map((b) => b.value), itemStyle: { color: '#1d5c2e', borderRadius: [0, 3, 3, 0] }, label: { show: true, position: 'right', color: '#163d22', fontWeight: 600 } }],
        };
    }, [breeds]);

    const financeChart = useMemo(() => (finance ? {
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

    return (
        <>
            <Head title="Dashboard" />
            <PageHeader title="Herd today" description={`Signed in as ${auth?.roleLabel}. Figures refresh by themselves every 30 seconds.`} />

            <StatRow>
                <Stat label="Goats on farm" value={stats.goats} note={`${stats.does} does, ${stats.bucks} bucks`} />
                <Stat label="Need attention" value={stats.unwell} note="Unwell or on watch" tone={stats.unwell ? 'alert' : undefined} />
                <Stat label="Pregnant" value={stats.pregnant} note="Confirmed" />
                <Stat label="Care overdue" value={stats.overdueCare} note="Vaccine or deworming" tone={stats.overdueCare ? 'alert' : undefined} />
                <Stat label="Tasks today" value={`${stats.tasksDone}/${stats.tasksTotal}`} note={`Feeding ${stats.feedingDone}/${stats.feedingTotal}`} />
            </StatRow>

            {/* CEO categories (D-046). Tiles animate in one after another. */}
            <Panel title="Herd by category" className="mt-4">
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                    {herd.map((h, i) => (
                        <motion.div
                            key={h.key}
                            initial={{ opacity: 0, y: 10, scale: 0.97 }} animate={{ opacity: 1, y: 0, scale: 1 }} transition={{ delay: i * 0.03, type: 'spring', stiffness: 380, damping: 28 }}
                            className={`rounded-2xl p-3 ${h.key === 'total' ? 'bg-cobalt-900 text-white' : (h.key === 'sick' || h.key === 'treatment') && h.value ? 'bg-crit-100' : 'bg-glaze'}`}
                        >
                            <div className={`text-2xl font-bold tabular-nums ${h.key === 'total' ? 'text-white' : 'text-cobalt-900'}`}>{h.value}</div>
                            <div className={`text-xs font-semibold leading-tight ${h.key === 'total' ? 'text-cobalt-200' : 'text-ink-soft'}`}>{h.label}</div>
                        </motion.div>
                    ))}
                </div>
            </Panel>

            {feeding ? <FeedingToday feeding={feeding} /> : null}

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
                <Panel title="Herd wall" description="All goats on the farm, by pen">
                    <HerdWall pens={wall} />
                </Panel>

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

            <div className={`mt-4 grid gap-4 lg:grid-cols-2 ${financeChart ? 'xl:grid-cols-3' : ''}`}>
                <Panel title="Average weight" description="All weighings, by month"><Chart option={growthChart} label="Average herd weight by month" height={220} /></Panel>
                <Panel title="Herd by breed" description="Goats on the farm"><Chart option={breedChart} label="Goats by breed" height={220} /></Panel>
                {financeChart ? <Panel title="Income and spending" description="Last 6 months, synthetic"><Chart option={financeChart} label="Monthly income and spending" height={220} /></Panel> : null}
            </div>

            <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
                <Panel title="Recent activity" flush><ActivityList activity={activity} /></Panel>
                <Panel title="Next kiddings" flush description="Dates use a demo 150-day gestation">
                    <ul className="divide-y divide-rule">
                        {kiddings.map((k) => (
                            <li key={k.goatId} className="flex items-center justify-between gap-2 px-4 py-2 text-sm">
                                <Link href={`/goats/${k.goatId}`} className="min-w-0 truncate font-semibold text-cobalt-800 hover:underline">{k.name ?? k.number}</Link>
                                <span className="shrink-0 text-ink-soft">{k.on} · {k.days < 0 ? `${-k.days}d over` : `${k.days}d`}</span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            </div>
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
            className="mt-4" actions={<Link href="/feeding" className="text-sm font-semibold text-turq-800 hover:underline">Feed now</Link>}>
            {feeding.lowStock.length ? <p className="mb-3 rounded-tile bg-watch-100 px-3 py-2 text-sm text-watch-700">Low or out of stock: {feeding.lowStock.join(', ')}</p> : null}
            {feeding.pens.length ? (
                <div className="overflow-x-auto">
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
            ) : <p className="text-sm text-ink-soft">No goats in any pen yet.</p>}
        </Panel>
    );
}
