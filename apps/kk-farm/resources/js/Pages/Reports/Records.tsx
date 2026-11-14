import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import DataGrid, { useGridFilters, type Col } from '../../components/DataGrid';
import { cx, PageHeader, Panel } from '../../components/ui';

/**
 * D-270/D-273: field-work reports as spreadsheets. "By date": one row per date with the day's numbers (click a date for
 * its goats); "All records": every record. Filters (any column, any condition, as many as needed) apply to both.
 */
type Kind = 'weight' | 'temp' | 'height' | 'move';
type Row = {
    on: string; goatId: string; number: string; value: number | null; change: number | null; days: number | null; by: string | null;
    bcs?: number | null; fever?: boolean; result?: string; from?: string | null; to?: string | null; reason?: string | null;
    breed?: string | null; sex?: string | null; pen?: string | null; role?: string | null; code?: string | null; status?: string;
};
type Props = { kind: Kind; kinds: Record<string, string>; rows: Row[] };

const UNIT: Record<Kind, string> = { weight: 'KG', temp: '°F', height: 'in', move: '' };
const VALUE: Record<Kind, string> = { weight: 'Weight', temp: 'Temperature', height: 'Height', move: '' };
const TITLE: Record<Kind, string> = { weight: 'Weighings Report', temp: 'Temperature Report', height: 'Height Report', move: 'Movements Report' };
const ABOUT: Record<Kind, string> = {
    weight: 'Every weighing, by date: how many goats, the average, and how many gained or lost since their weighing before.',
    temp: 'Every temperature taken, by date: how many goats, the average, and how many were outside the normal 101.3–103.5 °F.',
    height: 'Every height measured, by date: how many goats, the average, and how many grew.',
    move: 'Every move between PENs, by date: how many goats and where they went.',
};
const fmt = (on: string) => new Date(`${on}T12:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' });
const signed = (n: number) => `${n > 0 ? '+' : ''}${n}`;
const tone = (n: number | null | undefined, kind: Kind) => (kind === 'temp' || n === null || n === undefined || n === 0 ? '' : n > 0 ? 'text-ok-700' : 'text-crit-700');

type Day = { on: string; goats: number; avg: number | null; min: number | null; max: number | null; up: number; down: number; avgChange: number | null; fever: number; moves: string; by: string; list: Row[] };

export default function Records({ kind, kinds, rows }: Props) {
    const [view, setView] = useState<'date' | 'all'>('date');
    const unit = UNIT[kind];
    const recordCols = useMemo<Col<Row>[]>(() => [
        { key: 'on', label: 'Date', type: 'date', render: (r) => fmt(r.on) },
        { key: 'number', label: 'Goat', type: 'text', render: (r) => <Link href={`/goats/${r.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline" onClick={(e) => e.stopPropagation()}>{r.number}</Link>, total: 'count' },
        ...(kind !== 'move' ? [
            { key: 'value', label: `${VALUE[kind]} (${unit})`, type: 'number' as const, total: 'avg' as const, unit, render: (r: Row) => (r.value === null ? '—' : <span className={r.fever ? 'rounded bg-crit-100 px-1.5 font-semibold text-crit-700' : 'font-semibold'}>{r.value}</span>) },
            { key: 'change', label: `Change (${unit})`, type: 'number' as const, total: 'avg' as const, unit, render: (r: Row) => (r.change === null ? <span className="text-ink-faint">First</span> : <span className={tone(r.change, kind)}>{r.change > 0 ? '▲ ' : r.change < 0 ? '▼ ' : ''}{signed(r.change)}</span>) },
            { key: 'days', label: 'Days since last', type: 'number' as const },
        ] : [
            { key: 'from', label: 'From PEN', type: 'enum' as const },
            { key: 'to', label: 'To PEN', type: 'enum' as const },
            { key: 'reason', label: 'Reason', type: 'text' as const },
        ]),
        ...(kind === 'weight' ? [{ key: 'bcs', label: 'Body score', type: 'number' as const, total: 'avg' as const }] : []),
        ...(kind === 'temp' ? [{ key: 'result', label: 'Result', type: 'enum' as const }, { key: 'feverText', label: 'Outside normal', type: 'enum' as const, value: (r: Row) => (r.fever ? 'Yes' : 'No') }] : []),
        { key: 'breed', label: 'Breed', type: 'enum' },
        { key: 'sex', label: 'Sex', type: 'enum' },
        { key: 'role', label: 'Role', type: 'enum' },
        { key: 'code', label: 'Class code', type: 'enum' },
        { key: 'pen', label: 'PEN now', type: 'enum' },
        { key: 'status', label: 'Status', type: 'enum' },
        { key: 'by', label: 'By', type: 'enum' },
    ], [kind, unit]);
    const { rows: filtered, bar, api } = useGridFilters(`records-${kind}`, recordCols, rows);

    // "By date": the day's numbers from the filtered records
    const days = useMemo<Day[]>(() => {
        const by = new Map<string, Row[]>();
        for (const r of filtered) by.set(r.on, [...(by.get(r.on) ?? []), r]);
        return [...by.entries()].map(([on, list]) => {
            const vals = list.map((r) => r.value).filter((v): v is number => v !== null);
            const ch = list.map((r) => r.change).filter((v): v is number => v !== null);
            const routes = new Map<string, number>();
            list.forEach((r) => { const k = `${r.from ?? '?'} → ${r.to ?? '?'}`; routes.set(k, (routes.get(k) ?? 0) + 1); });
            return {
                on, list, goats: new Set(list.map((r) => r.goatId)).size,
                avg: vals.length ? Math.round((vals.reduce((s, v) => s + v, 0) / vals.length) * 10) / 10 : null, min: vals.length ? Math.min(...vals) : null, max: vals.length ? Math.max(...vals) : null,
                up: ch.filter((v) => v > 0).length, down: ch.filter((v) => v < 0).length, avgChange: ch.length ? Math.round((ch.reduce((s, v) => s + v, 0) / ch.length) * 100) / 100 : null,
                fever: list.filter((r) => r.fever).length, moves: [...routes.entries()].sort((a, b) => b[1] - a[1]).slice(0, 3).map(([k, n]) => `${k} (${n})`).join(', '),
                by: [...new Set(list.map((r) => r.by).filter(Boolean))].join(', '),
            };
        }).sort((a, b) => b.on.localeCompare(a.on));
    }, [filtered]);
    const dayCols = useMemo<Col<Day>[]>(() => [
        { key: 'on', label: 'Date', type: 'date', render: (d) => <span className="font-semibold text-cobalt-900">{fmt(d.on)}</span> },
        { key: 'goats', label: 'Goats', type: 'number', total: 'sum' },
        ...(kind !== 'move' ? [
            { key: 'avg', label: `Average (${unit})`, type: 'number' as const, total: 'avg' as const, unit },
            { key: 'min', label: 'Lowest', type: 'number' as const },
            { key: 'max', label: 'Highest', type: 'number' as const },
        ] : [{ key: 'moves', label: 'Main moves', type: 'text' as const }]),
        ...(kind === 'weight' || kind === 'height' ? [
            { key: 'up', label: kind === 'weight' ? 'Gained' : 'Grew', type: 'number' as const, total: 'sum' as const, render: (d: Day) => (d.up ? <span className="font-semibold text-ok-700">▲ {d.up}</span> : <span className="text-ink-faint">—</span>) },
            { key: 'down', label: kind === 'weight' ? 'Lost' : 'Shorter', type: 'number' as const, total: 'sum' as const, render: (d: Day) => (d.down ? <span className="font-semibold text-crit-700">▼ {d.down}</span> : <span className="text-ink-faint">—</span>) },
            { key: 'avgChange', label: `Average change (${unit})`, type: 'number' as const, render: (d: Day) => (d.avgChange === null ? <span className="text-ink-faint">—</span> : <span className={tone(d.avgChange, kind)}>{signed(d.avgChange)}</span>) },
        ] : []),
        ...(kind === 'temp' ? [{ key: 'fever', label: 'Outside normal', type: 'number' as const, total: 'sum' as const, render: (d: Day) => (d.fever ? <span className="rounded bg-crit-100 px-1.5 font-semibold text-crit-700">{d.fever}</span> : <span className="text-ink-faint">—</span>) }] : []),
        { key: 'by', label: 'By', type: 'text' },
    ], [kind, unit]);
    const inner = recordCols.filter((c) => c.key !== 'on');
    const seg = (on: boolean) => cx('min-h-9 px-4 text-sm font-semibold', on ? 'bg-cobalt-800 text-white' : 'bg-tile text-cobalt-800 hover:bg-glaze');

    return (
        <>
            <Head title={TITLE[kind]} />
            <PageHeader title={TITLE[kind]} description={ABOUT[kind]} />
            <div className="mb-4 inline-flex flex-wrap overflow-hidden rounded-tile border border-rule" role="tablist" aria-label="Report">
                {Object.entries(kinds).map(([k, label]) => <button key={k} type="button" role="tab" aria-selected={k === kind} onClick={() => router.get('/reports/records', { kind: k })} className={seg(k === kind)}>{label}</button>)}
            </div>
            <Panel flush>
                {bar}
                {view === 'date' ? (
                    <DataGrid<Day> id={`records-${kind}-days`} name={`${TITLE[kind]} by date`} cols={dayCols} rows={days} filterable={false} rowKey={(d) => d.on} empty="Nothing recorded"
                        toolbar={<ViewSwitch view={view} setView={setView} seg={seg} />}
                        expand={(d) => <DataGrid<Row> id={`records-${kind}-day`} name={`${TITLE[kind]} ${d.on}`} cols={inner} rows={d.list} filterable={false} pageSize={50} />} />
                ) : (
                    <DataGrid<Row> id={`records-${kind}-all`} name={`${TITLE[kind]} all records`} cols={recordCols} rows={filtered} filterable={false} filterApi={api} empty="Nothing recorded"
                        toolbar={<ViewSwitch view={view} setView={setView} seg={seg} />} />
                )}
            </Panel>
        </>
    );
}

function ViewSwitch({ view, setView, seg }: { view: 'date' | 'all'; setView: (v: 'date' | 'all') => void; seg: (on: boolean) => string }) {
    return (
        <div className="inline-flex overflow-hidden rounded-md border border-rule" role="group" aria-label="View">
            <button type="button" aria-pressed={view === 'date'} className={seg(view === 'date')} onClick={() => setView('date')}>By date</button>
            <button type="button" aria-pressed={view === 'all'} className={seg(view === 'all')} onClick={() => setView('all')}>All records</button>
        </div>
    );
}
