import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArtIcon } from '../components/ArtIcon';
import Chart from '../components/LazyChart';
import { Empty, Panel } from '../components/ui';
import { APPS } from '../lib/apps';
import { FARM } from '../lib/chartTheme';

type Kpi = { label: string; value: number | string; url: string | null; tone: 'good' | 'warning' | 'critical' | null; note: string | null };
type ChartDef = { title: string; kind: 'bar' | 'donut' | 'line'; labels: string[]; series: Array<{ name: string; data: number[] }>; unit: string | null };
type Alert = { key: string; label: string; severity: string; count: number; action: string; url: string; items: Array<{ text: string; sub: string | null; url: string | null }> };
type Props = { app: string; title: string; kpis: Kpi[]; charts: ChartDef[]; alerts: Alert[] };

const TONE: Record<string, string> = { good: 'bg-ok-700', warning: 'bg-watch-400', critical: 'bg-crit-500' };
const COLORS = [FARM.grass, FARM.wheat, '#3b82c4', '#c2416b', '#7a4fa0', '#12663f', '#a77a0f', '#5d7163'];

/** D-257: an app's own dashboard: its numbers (zeros hidden), charts, and what needs attention. Everything opens its records. */
export default function AppDashboard({ app, title, kpis, charts, alerts }: Props) {
    const art = APPS.find((a) => a.key === app)?.art;
    return (
        <>
            <Head title={`${title} Dashboard`} />
            <div className="mb-4 flex items-center gap-3">
                {art ? <ArtIcon name={art} size={48} /> : null}
                <div><h1 className="text-2xl font-bold text-cobalt-900">{title}</h1><p className="text-sm text-ink-soft">Today at a glance. Tap a number to open those records.</p></div>
            </div>
            {kpis.length ? (
                <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">{kpis.map((k) => {
                    const body = (
                        <>
                            <span className={`block h-1 w-8 rounded-full ${k.tone ? TONE[k.tone] : 'bg-cobalt-600'}`} aria-hidden />
                            <span className="mt-2 block text-2xl font-bold tabular-nums text-cobalt-900">{typeof k.value === 'number' ? k.value.toLocaleString('en-PK') : k.value}</span>
                            <span className="text-sm text-ink-soft">{k.label}</span>
                        </>
                    );
                    return k.url ? <Link key={k.label} href={k.url} className="kk-lift rounded-tile border border-rule bg-tile p-3 shadow-tile">{body}</Link> : <div key={k.label} className="rounded-tile border border-rule bg-tile p-3 shadow-tile">{body}</div>;
                })}</div>
            ) : null}
            <div className={`grid gap-4 ${charts.length && alerts.length ? 'lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]' : ''}`}>
                {charts.length ? <div className="space-y-4">{charts.map((c) => <ChartPanel key={c.title} c={c} />)}</div> : null}
                {alerts.length ? <Attention alerts={alerts} /> : null}
            </div>
            {!kpis.length && !charts.length && !alerts.length ? <Panel><Empty title="Nothing recorded here yet">Numbers and charts appear as records are added in this app.</Empty></Panel> : null}
        </>
    );
}

function ChartPanel({ c }: { c: ChartDef }) {
    const fmt = (v: number) => (c.unit === 'PKR' ? `PKR ${Math.round(v).toLocaleString('en-PK')}` : `${v.toLocaleString('en-PK')}${c.unit && c.unit !== 'PKR' ? ` ${c.unit}` : ''}`);
    const option = c.kind === 'donut' ? {
        tooltip: { trigger: 'item', valueFormatter: fmt },
        legend: { bottom: 0, itemWidth: 10, itemHeight: 10, textStyle: { color: FARM.ink } },
        series: [{ type: 'pie', radius: ['52%', '76%'], center: ['50%', '44%'], padAngle: 2, itemStyle: { borderRadius: 4, borderColor: '#fff', borderWidth: 2 },
            label: { show: true, formatter: '{b}\n{c}', color: FARM.ink }, data: c.labels.map((name, i) => ({ name, value: c.series[0].data[i], itemStyle: { color: COLORS[i % COLORS.length] } })) }],
    } : {
        tooltip: { trigger: 'axis', valueFormatter: fmt },
        legend: c.series.length > 1 ? { top: 0, itemWidth: 10, itemHeight: 10, textStyle: { color: FARM.ink } } : undefined,
        grid: { left: 8, right: 12, top: c.series.length > 1 ? 30 : 12, bottom: 4, containLabel: true },
        xAxis: { type: 'category', data: c.labels, axisLine: { lineStyle: { color: FARM.rule } }, axisLabel: { color: FARM.ink, hideOverlap: true } },
        yAxis: { type: 'value', splitLine: { lineStyle: { color: FARM.rule } }, axisLabel: { color: FARM.ink } },
        series: c.series.map((s, i) => c.kind === 'line'
            ? { name: s.name, type: 'line', data: s.data, smooth: true, symbolSize: 6, lineStyle: { width: 2.5, color: COLORS[i] }, itemStyle: { color: COLORS[i] }, areaStyle: { opacity: 0.08 } }
            : { name: s.name, type: 'bar', data: s.data, barMaxWidth: 28, itemStyle: { color: COLORS[i], borderRadius: [4, 4, 0, 0] } }),
    };
    return <Panel title={c.title}><Chart option={option} label={c.title} height={c.kind === 'donut' ? 260 : 240} /></Panel>;
}

function Attention({ alerts }: { alerts: Alert[] }) {
    const [all, setAll] = useState(false);
    const shown = all ? alerts : alerts.slice(0, 5);
    const dot = (s: string) => (s === 'critical' ? 'bg-crit-500' : s === 'warning' ? 'bg-watch-400' : 'bg-cobalt-600');
    return (
        <Panel title="Needs attention" flush>
            <ul className="divide-y divide-rule">{shown.map((a) => (
                <li key={a.key} className="px-4 py-3">
                    <button type="button" onClick={() => router.visit(a.url)} className="flex w-full items-start gap-3 text-start">
                        <span className={`mt-1.5 size-2.5 shrink-0 rounded-full ${dot(a.severity)}`} aria-hidden />
                        <span className="min-w-0 flex-1"><span className="font-semibold text-cobalt-900">{a.label}</span><span className="block text-xs text-ink-soft">{a.action}</span></span>
                        <span className="text-lg font-bold tabular-nums text-cobalt-900">{a.count}</span>
                    </button>
                    {a.items.length ? <div className="mt-1.5 flex flex-wrap gap-1.5 ps-5">{a.items.slice(0, 5).map((i, n) => i.url
                        ? <Link key={n} href={i.url} className="rounded-full bg-glaze px-2 py-0.5 font-mono text-xs font-semibold text-cobalt-800 hover:underline">{i.text}</Link>
                        : <span key={n} className="rounded-full bg-glaze px-2 py-0.5 text-xs">{i.text}</span>)}</div> : null}
                </li>
            ))}</ul>
            {alerts.length > 5 ? <div className="border-t border-rule px-4 py-2"><button type="button" className="text-sm font-semibold text-cobalt-800 hover:underline" onClick={() => setAll(!all)}>{all ? 'Show fewer' : `Show ${alerts.length - 5} more`}</button></div> : null}
        </Panel>
    );
}
