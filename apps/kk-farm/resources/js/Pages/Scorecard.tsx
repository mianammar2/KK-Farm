import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import Chart from '../components/LazyChart';
import { PageHeader, Panel } from '../components/ui';
import { FARM, GOAT_PATH } from '../lib/chartTheme';

type Item = { label: string; value: number | string | null; url?: string | null; unit?: string };
type Group = { key: string; title: string; note?: string; items: Item[] };

/** D-238: brand green for counts, the app's female / male pair, status colours (always with their label beside them). */
const C = { bar: FARM.grass, female: '#e5739a', male: '#3b82c4', crit: '#b3261e', watch: '#a77a0f', ok: '#2e7d4f', info: '#2f6f9f', ink: '#3d5446', muted: '#7a867b', rule: '#ebece2' };
const STATUS: Record<string, string> = {
    'Sick': C.crit, 'Under treatment': C.crit, 'Critical': C.crit, 'Under observation / recovery': C.watch, 'Veterinary hold': C.watch,
    'Sale eligible (clearance pending)': C.watch, 'Sale ready': C.ok, 'Sale blocked': C.crit, 'Reserved': C.info, 'Sold this year': C.bar,
    'Qualified, approval pending': C.watch, 'Maturity review': C.info, 'Not eligible yet': C.muted, 'Approved': C.ok,
};
const num = (i: Item) => (typeof i.value === 'number' ? i.value : Number(i.value));
const fmt = (i: Item) => (i.unit === 'PKR' ? `PKR ${num(i).toLocaleString('en-PK')}` : `${num(i).toLocaleString('en-PK')}${i.unit && i.unit !== 'PKR' ? ` ${i.unit}` : ''}`);
const go = (items: Item[]) => (name: string) => { const u = items.find((i) => i.label === name)?.url; if (u) router.visit(u); };

/** D-233 / D-238: the CEO scorecard as a picture of the herd. Zero values are hidden; every bar opens its goats. */
export default function Scorecard({ groups, asOf }: { groups: Group[]; asOf: string }) {
    const g = useMemo(() => Object.fromEntries(groups.map((x) => [x.key, { ...x, items: x.items.filter((i) => i.value !== null && i.value !== 0) }])) as Record<string, Group>, [groups]);
    const find = (key: string, label: string) => g[key]?.items.find((i) => i.label === label);
    const hero = [
        { label: 'Goats on the farm', item: find('population', 'Total'), tone: C.bar },
        { label: 'Pregnant', item: find('repro', 'Pregnant'), tone: C.female },
        { label: 'Milking', item: find('dairy', 'Milking'), tone: C.info },
        { label: 'Breeding approval pending', item: find('eligibility', 'Qualified, approval pending'), tone: C.watch },
        { label: 'Sale ready', item: find('commercial', 'Sale ready'), tone: C.ok },
        { label: 'Sick', item: find('health', 'Sick'), tone: C.crit },
    ].filter((h): h is { label: string; item: Item; tone: string } => !!h.item);
    const order = ['age', 'teeth', 'stock', 'eligibility', 'repro', 'dairy', 'fattening', 'commercial', 'health', 'productivity', 'money'];
    return (
        <>
            <Head title="CEO Scorecard" />
            <PageHeader title="CEO Scorecard" description={`The herd at a glance from the live records (${asOf}). Main roles add up to the herd; overlapping statuses (pregnant and milking) are counted on their own. Tap a bar or a number to open those goats.`} />
            <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">{hero.map((h) => (
                <Link key={h.label} href={h.item.url ?? '#'} className="kk-lift rounded-tile border border-rule bg-tile p-3 shadow-tile">
                    <span className="block h-1 w-8 rounded-full" style={{ background: h.tone }} aria-hidden />
                    <span className="mt-2 block text-3xl font-bold tabular-nums text-cobalt-900">{fmt(h.item)}</span>
                    <span className="text-sm text-ink-soft">{h.label}</span>
                </Link>
            ))}</div>
            <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                {g.population?.items.length ? <Population g={g.population} /> : null}
                {order.filter((k) => g[k]?.items.length).map((k) => <GroupCard key={k} g={g[k]} />)}
            </div>
        </>
    );
}

/** Female / male donut with the total in the middle; births and deaths below. */
function Population({ g }: { g: Group }) {
    const f = g.items.find((i) => i.label === 'Female'); const m = g.items.find((i) => i.label === 'Male'); const total = g.items.find((i) => i.label === 'Total');
    const rest = g.items.filter((i) => !['Total', 'Female', 'Male'].includes(i.label));
    const data = [f && { name: 'Female', value: num(f), itemStyle: { color: C.female } }, m && { name: 'Male', value: num(m), itemStyle: { color: C.male } }].filter(Boolean);
    const option = {
        tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
        legend: { bottom: 0, itemWidth: 10, itemHeight: 10, textStyle: { color: C.ink } },
        series: [{ type: 'pie', radius: ['56%', '78%'], center: ['50%', '46%'], padAngle: 2, itemStyle: { borderRadius: 4, borderColor: '#fff', borderWidth: 2 },
            label: { show: true, position: 'outside', formatter: '{b}\n{c}', color: C.ink, fontWeight: 600 }, labelLine: { length: 8, length2: 6 }, data }],
    };
    return (
        <Panel title={g.title}>
            <div className="relative">
                <Chart option={option} height={230} label="Goats by gender" onSelect={go(g.items)} />
                {total ? <Link href={total.url ?? '#'} className="pointer-events-auto absolute top-[46%] left-1/2 -translate-x-1/2 -translate-y-1/2 text-center"><span className="block text-3xl font-bold tabular-nums text-cobalt-900">{fmt(total)}</span><span className="text-xs text-ink-soft">goats</span></Link> : null}
            </div>
            {rest.length ? <div className="mt-2 grid grid-cols-2 gap-2">{rest.map((i) => <Tile key={i.label} i={i} />)}</div> : null}
        </Panel>
    );
}

function GroupCard({ g }: { g: Group }) {
    const counts = g.items.filter((i) => !i.unit);
    const kpis = g.items.filter((i) => i.unit);
    return (
        <Panel title={g.title} description={g.note}>
            {counts.length ? (g.key === 'age' ? <GoatBars items={counts} /> : <Bars items={counts} status={['health', 'commercial', 'eligibility'].includes(g.key)} />) : null}
            {kpis.length ? <div className={`grid grid-cols-2 gap-2 ${counts.length ? 'mt-3' : ''}`}>{kpis.map((i) => (i.unit === '%' ? <Ring key={i.label} i={i} /> : <Tile key={i.label} i={i} />))}</div> : null}
        </Panel>
    );
}

/** One bar per line, value at its end; status groups wear status colours (their label always on the axis). */
function Bars({ items, status }: { items: Item[]; status: boolean }) {
    const [all, setAll] = useState(false);
    const shown = all ? items : items.slice(0, 5);
    const option = {
        grid: { left: 8, right: 44, top: 4, bottom: 4, containLabel: true },
        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
        xAxis: { type: 'value', show: false },
        yAxis: { type: 'category', inverse: true, data: shown.map((i) => i.label), axisLine: { show: false }, axisTick: { show: false }, axisLabel: { color: C.ink, width: 170, overflow: 'truncate' } },
        series: [{ type: 'bar', barMaxWidth: 16, data: shown.map((i) => ({ value: num(i), itemStyle: { color: status ? STATUS[i.label] ?? C.bar : C.bar, borderRadius: [0, 4, 4, 0] } })),
            label: { show: true, position: 'right', color: C.ink, fontWeight: 700 }, showBackground: true, backgroundStyle: { color: C.rule, borderRadius: [0, 4, 4, 0] } }],
    };
    return (
        <>
            <Chart option={option} height={shown.length * 34 + 12} label="Counts" onSelect={go(items)} />
            {items.length > 5 ? <button type="button" className="mt-1 text-xs font-semibold text-cobalt-800 hover:underline" onClick={() => setAll(!all)}>{all ? 'Show less' : `Show ${items.length - 5} more`}</button> : null}
        </>
    );
}

/** Age stages drawn as rows of goats, the count at the end. */
function GoatBars({ items }: { items: Item[] }) {
    const option = {
        grid: { left: 8, right: 44, top: 4, bottom: 4, containLabel: true },
        tooltip: { trigger: 'axis', axisPointer: { type: 'none' } },
        xAxis: { type: 'value', show: false, max: Math.max(...items.map(num)) * 1.05 },
        yAxis: { type: 'category', inverse: true, data: items.map((i) => i.label), axisLine: { show: false }, axisTick: { show: false }, axisLabel: { color: C.ink } },
        series: [{ type: 'pictorialBar', symbol: GOAT_PATH, symbolRepeat: true, symbolSize: [20, 16], symbolMargin: 2, symbolClip: true, itemStyle: { color: C.bar },
            data: items.map((i) => num(i)), label: { show: true, position: 'right', color: C.ink, fontWeight: 700 } }],
    };
    return <Chart option={option} height={items.length * 36 + 12} label="Goats by age stage" onSelect={go(items)} />;
}

function Tile({ i }: { i: Item }) {
    const body = <><span className={`block text-lg font-bold tabular-nums ${num(i) < 0 ? 'text-crit-700' : 'text-cobalt-900'}`}>{fmt(i)}</span><span className="text-xs text-ink-soft">{i.label}</span></>;
    return i.url ? <Link href={i.url} className="rounded-tile bg-glaze px-3 py-2 hover:ring-1 hover:ring-rule">{body}</Link> : <div className="rounded-tile bg-glaze px-3 py-2">{body}</div>;
}

/** A percentage as a ring, the number in the middle. */
function Ring({ i }: { i: Item }) {
    const pct = Math.max(0, Math.min(100, num(i)));
    const ring = (
        <span className="flex items-center gap-3">
            <span className="grid size-14 shrink-0 place-items-center rounded-full" style={{ background: `conic-gradient(${C.bar} ${pct * 3.6}deg, ${C.rule} 0)` }} role="img" aria-label={`${pct}%`}>
                <span className="grid size-10 place-items-center rounded-full bg-tile text-sm font-bold tabular-nums text-cobalt-900">{pct}%</span>
            </span>
            <span className="text-xs text-ink-soft">{i.label}</span>
        </span>
    );
    return i.url ? <Link href={i.url} className="rounded-tile bg-glaze px-3 py-2 hover:ring-1 hover:ring-rule">{ring}</Link> : <div className="rounded-tile bg-glaze px-3 py-2">{ring}</div>;
}
