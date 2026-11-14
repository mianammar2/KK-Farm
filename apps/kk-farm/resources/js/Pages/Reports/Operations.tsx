import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import DataGrid, { useGridFilters, type Col } from '../../components/DataGrid';
import { cx, PageHeader, Panel } from '../../components/ui';
import CorrectRecord from '../../components/CorrectRecord';
import Sheet from '../../components/Sheet';

/**
 * D-307: the Operations Log: every operation done on a goat in any app, as one spreadsheet. The calendar picks a day
 * From / To and the Operation list pick what to see; the spreadsheet's own filters do the rest (D-315).
 */
type Row = {
    key: string; txn: string; op: string; app: string; operation: string; on: string; entered: string | null; sort: string; goatId: string | null; number: string | null;
    detail: string | null; by: string | null; source: string; fix: (Record<string, unknown> & { id: number; link?: string; fields?: { key: string; label: string; type: 'date' | 'number' | 'text'; value: string | number | null }[] }) | null; breed: string | null; sex: string | null; pen: string | null; status: string | null;
};
type Op = { key: string; app: string; label: string };

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const fmt = (on?: string | null) => (on ? `${on.slice(8, 10)}/${on.slice(5, 7)}/${on.slice(0, 4)}` : '—');
const pct = (n: number, of: number) => (of ? `${Math.round((n / of) * 1000) / 10}%` : '0%');

export default function Operations({ rows, ops, app }: { rows: Row[]; ops: Op[]; app: string | null }) {
    const [picked, setPicked] = useState<string[]>([]); // operation keys; none = all
    const day: string | null = null; // D-315: the calendar is gone; dates come from From / To
    const [fixing, setFixing] = useState<Row | null>(null); // D-311
    const [from, setFrom] = useState(''); // D-309: date range (with the calendar's day on top)
    const [to, setTo] = useState('');
    const inRange = (on: string) => (day ? on === day : (!from || on >= from) && (!to || on <= to));
    const preset = (days: number | 'month' | null) => {
        
        if (days === null) { setFrom(''); setTo(''); return; }
        const t = new Date(); setTo(iso(t));
        setFrom(days === 'month' ? iso(new Date(t.getFullYear(), t.getMonth(), 1)) : iso(new Date(t.getFullYear(), t.getMonth(), t.getDate() - days + 1)));
    };

    const cols = useMemo<Col<Row>[]>(() => { const all: Col<Row>[] = [
        ...(rows.some((r) => r.fix) ? [{ key: 'fixIt', label: 'Correct', type: 'text' as const, value: () => '', render: (r: Row) => (r.fix?.link ? <Link href={r.fix.link} className="inline-flex h-8 items-center rounded-md border border-rule bg-tile px-2.5 text-sm text-cobalt-800 hover:bg-glaze">Correct</Link> : r.fix ? <button type="button" onClick={() => setFixing(r)} className="h-8 rounded-md border border-rule bg-tile px-2.5 text-sm text-cobalt-800 hover:bg-glaze">Correct</button> : null) }] : []), // D-311
        { key: 'txn', label: 'Transaction ID', type: 'text', render: (r) => <span className="font-mono text-sm">{r.txn}</span> }, // D-314
        { key: 'on', label: 'Date', type: 'date', render: (r) => fmt(r.on) },
        { key: 'entered', label: 'Entered on', type: 'text' },
        { key: 'number', label: 'Goat', type: 'text', total: 'count', render: (r) => (r.goatId ? <Link href={`/goats/${r.goatId}`} className="font-mono text-cobalt-800 hover:underline">{r.number}</Link> : '—') },
        ...(app ? [] : [{ key: 'app', label: 'App', type: 'enum' as const }]),
        { key: 'operation', label: 'Operation', type: 'enum' },
        { key: 'detail', label: 'Details', type: 'text', wrap: true },
        { key: 'by', label: 'By', type: 'enum' },
        { key: 'source', label: 'Source', type: 'enum' },
        { key: 'breed', label: 'Breed', type: 'enum' },
        { key: 'sex', label: 'Sex', type: 'enum' },
        { key: 'pen', label: 'PEN now', type: 'enum' },
        { key: 'status', label: 'Status', type: 'enum' },
    ]; return all.filter((c) => !['number', 'breed', 'sex', 'pen', 'status'].includes(c.key) || rows.some((r) => r.goatId)); }, [rows, app]); // no goat in this app: no goat columns
    // D-315: dates and the operation apply first, then the spreadsheet's own filters (so its row count matches)
    const byDay = useMemo(() => rows.filter((r) => inRange(r.on)), [rows, day, from, to]); // eslint-disable-line react-hooks/exhaustive-deps
    const picks = useMemo(() => byDay.filter((r) => !picked.length || picked.includes(r.op)), [byDay, picked]);
    const { rows: shown, bar, api } = useGridFilters('operations-log', cols, picks);
    const perOp = useMemo(() => { const m = new Map<string, number>(); byDay.forEach((r) => m.set(r.op, (m.get(r.op) ?? 0) + 1)); return m; }, [byDay]);

    return (
        <>
            <Head title={app ? `${app} Operations Log` : 'Operations Log'} />
            <PageHeader title={app ? `${app} Operations Log` : 'Operations Log: all apps'} description={`Every operation done ${app ? `in ${app}` : 'in every app'}: who did what, on which day. Pick dates and an operation, or filter any column.`} />
            <Panel className="mb-4">
                <div className="flex flex-wrap items-end gap-3">
                    <label className="text-sm text-ink-soft">From<input type="date" className="field mt-1 block w-44" value={from} max={to || undefined} onChange={(e) => { setFrom(e.target.value); }} /></label>
                    <label className="text-sm text-ink-soft">To<input type="date" className="field mt-1 block w-44" value={to} min={from || undefined} onChange={(e) => { setTo(e.target.value); }} /></label>
                    <label className="text-sm text-ink-soft">Operation{/* D-315: one list of this app's operations */}
                        <select className="field mt-1 block w-56" value={picked[0] ?? ''} onChange={(e) => setPicked(e.target.value ? [e.target.value] : [])}>
                            <option value="">All operations ({byDay.length})</option>
                            {ops.map((o) => <option key={o.key} value={o.key}>{o.label} ({perOp.get(o.key) ?? 0})</option>)}
                        </select>
                    </label>
                    <div className="flex flex-wrap gap-2">
                        {([['Today', 1], ['Last 7 days', 7], ['Last 30 days', 30], ['This month', 'month'], ['All dates', null]] as const).map(([label, d]) => (
                            <button key={label} type="button" onClick={() => preset(d)} className="h-11 rounded-md border border-rule bg-tile px-3 text-sm text-cobalt-900 hover:bg-glaze">{label}</button>
                        ))}
                    </div>
                    <span className="ml-auto text-sm text-ink-soft">{from || to ? `${from ? fmt(from) : 'start'} to ${to ? fmt(to) : 'today'}` : 'All dates'} · {shown.length} operations</span>
                </div>
            </Panel>
            {fixing?.fix ? <Sheet onClose={() => setFixing(null)}><CorrectRecord op={fixing.op} label={fixing.operation} goat={fixing.number} fix={fixing.fix} onDone={() => setFixing(null)} /></Sheet> : null}
            <Panel flush>
                {bar}
                <DataGrid<Row> id="operations-log-grid" name={app ? `${app} Operations Log` : 'Operations Log'} cols={cols} rows={shown} rowKey={(r) => r.key} filterable={false} filterApi={api} pageSize={50} empty="No operation matches" />
            </Panel>
        </>
    );
}
