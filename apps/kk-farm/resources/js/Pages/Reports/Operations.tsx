import { Head, Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useMemo, useState } from 'react';
import DataGrid, { useGridFilters, type Col } from '../../components/DataGrid';
import { cx, PageHeader, Panel } from '../../components/ui';

/**
 * D-307: the Operations Log: every operation done on a goat in any app, as one spreadsheet. The calendar picks a day
 * (each day shows how many operations), the operation buttons pick what (with their count and share), and the
 * spreadsheet's own filters do the rest.
 */
type Row = {
    key: string; op: string; app: string; operation: string; on: string; entered: string | null; sort: string; goatId: string | null; number: string | null;
    detail: string | null; by: string | null; source: string; breed: string | null; sex: string | null; pen: string | null; status: string | null;
};
type Op = { key: string; app: string; label: string };

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const fmt = (on: string) => `${on.slice(8, 10)}/${on.slice(5, 7)}/${on.slice(0, 4)}`;
const pct = (n: number, of: number) => (of ? `${Math.round((n / of) * 1000) / 10}%` : '0%');

export default function Operations({ rows, ops }: { rows: Row[]; ops: Op[] }) {
    const [picked, setPicked] = useState<string[]>([]); // operation keys; none = all
    const [day, setDay] = useState<string | null>(null);
    const [month, setMonth] = useState(() => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), 1); });

    const cols = useMemo<Col<Row>[]>(() => [
        { key: 'on', label: 'Date', type: 'date', render: (r) => fmt(r.on) },
        { key: 'entered', label: 'Entered on', type: 'text' },
        { key: 'number', label: 'Goat', type: 'text', total: 'count', render: (r) => (r.goatId ? <Link href={`/goats/${r.goatId}`} className="font-mono text-cobalt-800 hover:underline">{r.number}</Link> : '—') },
        { key: 'app', label: 'App', type: 'enum' },
        { key: 'operation', label: 'Operation', type: 'enum' },
        { key: 'detail', label: 'Details', type: 'text', wrap: true },
        { key: 'by', label: 'By', type: 'enum' },
        { key: 'source', label: 'Source', type: 'enum' },
        { key: 'breed', label: 'Breed', type: 'enum' },
        { key: 'sex', label: 'Sex', type: 'enum' },
        { key: 'pen', label: 'PEN now', type: 'enum' },
        { key: 'status', label: 'Status', type: 'enum' },
    ], []);
    const { rows: gridRows, bar, api } = useGridFilters('operations-log', cols, rows);
    const byOp = useMemo(() => gridRows.filter((r) => !picked.length || picked.includes(r.op)), [gridRows, picked]);
    const byDay = useMemo(() => gridRows.filter((r) => !day || r.on === day), [gridRows, day]);
    const shown = useMemo(() => byOp.filter((r) => !day || r.on === day), [byOp, day]);

    // the calendar counts what the operation buttons pick; the buttons count what the calendar picks
    const perDay = useMemo(() => { const m = new Map<string, number>(); byOp.forEach((r) => m.set(r.on, (m.get(r.on) ?? 0) + 1)); return m; }, [byOp]);
    const perOp = useMemo(() => { const m = new Map<string, number>(); byDay.forEach((r) => m.set(r.op, (m.get(r.op) ?? 0) + 1)); return m; }, [byDay]);
    const max = Math.max(1, ...[...perDay.values()]);
    const cells = useMemo(() => {
        const first = new Date(month); const lead = (first.getDay() + 6) % 7; // weeks start on Monday
        const days = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
        return [...Array(lead).fill(null), ...Array.from({ length: days }, (_, i) => iso(new Date(month.getFullYear(), month.getMonth(), i + 1)))];
    }, [month]);
    const today = iso(new Date());
    const shift = (n: number) => setMonth(new Date(month.getFullYear(), month.getMonth() + n, 1));
    const toggle = (k: string) => setPicked((p) => (p.includes(k) ? p.filter((x) => x !== k) : [...p, k]));
    const apps = [...new Set(ops.map((o) => o.app))];

    return (
        <>
            <Head title="Operations Log" />
            <PageHeader title="Operations Log" description="Every operation done on a goat, in every app: who did what, to which goat, on which day. Pick a day on the calendar, pick operations, or filter any column." />
            <div className="mb-4 grid gap-4 lg:grid-cols-[22rem_1fr]">
                <Panel>
                    <div className="mb-2 flex items-center justify-between">
                        <button type="button" onClick={() => shift(-1)} aria-label="Month before" className="grid size-9 place-items-center rounded-md hover:bg-glaze"><ChevronLeft size={18} aria-hidden /></button>
                        <span className="text-base text-cobalt-900">{String(month.getMonth() + 1).padStart(2, '0')}/{month.getFullYear()}</span>
                        <button type="button" onClick={() => shift(1)} aria-label="Month after" className="grid size-9 place-items-center rounded-md hover:bg-glaze"><ChevronRight size={18} aria-hidden /></button>
                    </div>
                    <div className="grid grid-cols-7 gap-1">
                        {cells.map((d, i) => {
                            if (!d) return <span key={`x${i}`} />;
                            const n = perDay.get(d) ?? 0;
                            return (
                                <button key={d} type="button" onClick={() => setDay(day === d ? null : d)} aria-pressed={day === d} aria-label={`${fmt(d)}: ${n} operations`}
                                    className={cx('flex h-12 flex-col items-center justify-center rounded-md border text-sm', day === d ? 'border-cobalt-800 bg-cobalt-800 text-white' : d === today ? 'border-cobalt-400' : 'border-transparent hover:border-rule')}
                                    style={day !== d && n ? { backgroundColor: `rgba(21, 94, 60, ${0.08 + (n / max) * 0.32})` } : undefined}>
                                    <span>{Number(d.slice(8))}</span>
                                    <span className={cx('text-xs', day === d ? 'text-white' : n ? 'text-cobalt-900' : 'text-ink-faint')}>{n || '·'}</span>
                                </button>
                            );
                        })}
                    </div>
                    <div className="mt-3 flex items-center justify-between text-sm text-ink-soft">
                        <span>{day ? `${fmt(day)} · ${shown.length} operations` : 'All dates'}</span>
                        {day ? <button type="button" onClick={() => setDay(null)} className="text-cobalt-800 hover:underline">Show all dates</button> : <button type="button" onClick={() => { setDay(today); setMonth(new Date(new Date().getFullYear(), new Date().getMonth(), 1)); }} className="text-cobalt-800 hover:underline">Today</button>}
                    </div>
                </Panel>
                <Panel>
                    <div className="mb-3 flex items-center justify-between">
                        <span className="text-base text-cobalt-900">Operations{day ? ` on ${fmt(day)}` : ''} · {byDay.length}</span>
                        {picked.length ? <button type="button" onClick={() => setPicked([])} className="text-sm text-cobalt-800 hover:underline">Show all operations</button> : null}
                    </div>
                    <div className="space-y-3">
                        {apps.filter((app) => ops.some((o) => o.app === app && (perOp.get(o.key) || picked.includes(o.key)))).map((app) => (
                            <div key={app}>
                                <div className="mb-1.5 text-xs uppercase tracking-wide text-ink-soft">{app}</div>
                                <div className="flex flex-wrap gap-2">
                                    {ops.filter((o) => o.app === app && (perOp.get(o.key) || picked.includes(o.key))).map((o) => { // zero: hidden
                                        const n = perOp.get(o.key) ?? 0; const on = picked.includes(o.key);
                                        return (
                                            <button key={o.key} type="button" onClick={() => toggle(o.key)} aria-pressed={on}
                                                className={cx('inline-flex h-10 items-center gap-2 rounded-md border px-3 text-sm', on ? 'border-cobalt-800 bg-cobalt-800 text-white' : 'border-rule bg-tile text-cobalt-900 hover:bg-glaze')}>
                                                {o.label}<span className={cx('rounded px-1.5 text-xs', on ? 'bg-white/20' : 'bg-glaze text-ink-soft')}>{n} · {pct(n, byDay.length)}</span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        ))}
                    </div>
                </Panel>
            </div>
            <Panel flush>
                {bar}
                <DataGrid<Row> id="operations-log-grid" name="Operations Log" cols={cols} rows={shown} rowKey={(r) => r.key} filterable={false} filterApi={api} pageSize={50} empty="No operation matches" />
            </Panel>
        </>
    );
}
