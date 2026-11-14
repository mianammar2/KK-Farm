import { ArrowDown, ArrowUp, ArrowUpDown, ChevronDown, ChevronRight, Download, Filter, Plus, Search, X } from 'lucide-react';
import { Fragment, useEffect, useMemo, useState, type ReactNode } from 'react';
import { usePaged } from './Pager';
import { cx } from './ui';

/**
 * D-273: every report as a spreadsheet: gridlines and row numbers, a header that stays in view, click a heading to
 * sort, a quick search, "+ Add filter" on any column with any condition (as many as needed, all must match), a totals
 * row, and Export to Excel of exactly what is shown (all pages). Filters and sort are remembered per report on this device.
 */
export type ColType = 'text' | 'number' | 'date' | 'enum';
export type Col<T> = {
    key: string;
    label: string;
    type?: ColType;
    value?: (row: T) => string | number | null | undefined; // what is sorted, filtered and exported (default: row[key])
    render?: (row: T) => ReactNode; // how the cell looks (default: the value)
    total?: 'sum' | 'avg' | 'count'; // the totals row
    unit?: string; // shown after numbers in the totals row
    width?: string;
};
type Filter = { id: number; col: string; op: string; a: string; b: string };

const OPS: Record<ColType, Array<[string, string]>> = {
    text: [['has', 'contains'], ['not_has', 'does not contain'], ['is', 'is'], ['not', 'is not'], ['starts', 'starts with'], ['empty', 'is empty'], ['filled', 'is not empty']],
    enum: [['is', 'is'], ['not', 'is not'], ['empty', 'is empty'], ['filled', 'is not empty']],
    number: [['eq', '='], ['ne', '≠'], ['gt', '>'], ['gte', '≥'], ['lt', '<'], ['lte', '≤'], ['between', 'between'], ['empty', 'is empty'], ['filled', 'is not empty']],
    date: [['on', 'on'], ['before', 'before'], ['after', 'after'], ['between', 'between'], ['last', 'in the last … days'], ['empty', 'is empty']],
};
const NO_VALUE = ['empty', 'filled'];

function test(v: string | number | null | undefined, type: ColType, f: Filter): boolean {
    const empty = v === null || v === undefined || v === '';
    if (f.op === 'empty') return empty;
    if (f.op === 'filled') return !empty;
    if (f.a === '' && f.op !== 'between') return true; // a filter still being typed does not hide anything
    if (empty) return f.op === 'not_has' || f.op === 'not' || f.op === 'ne';
    if (type === 'number') {
        const n = Number(v), a = Number(f.a), b = Number(f.b);
        return ({ eq: n === a, ne: n !== a, gt: n > a, gte: n >= a, lt: n < a, lte: n <= a, between: (f.a === '' || n >= a) && (f.b === '' || n <= b) } as Record<string, boolean>)[f.op] ?? true;
    }
    if (type === 'date') {
        const d = String(v).slice(0, 10);
        if (f.op === 'last') { const from = new Date(Date.now() - Number(f.a) * 86400000).toISOString().slice(0, 10); return d >= from; }
        return ({ on: d === f.a, before: d < f.a, after: d > f.a, between: (f.a === '' || d >= f.a) && (f.b === '' || d <= f.b) } as Record<string, boolean>)[f.op] ?? true;
    }
    const s = String(v).toLowerCase(), a = f.a.toLowerCase();
    return ({ has: s.includes(a), not_has: !s.includes(a), is: s === a, not: s !== a, starts: s.startsWith(a) } as Record<string, boolean>)[f.op] ?? true;
}

const load = <V,>(key: string, fallback: V): V => { try { const v = localStorage.getItem(key); return v ? (JSON.parse(v) as V) : fallback; } catch { return fallback; } };
const save = (key: string, v: unknown) => { try { localStorage.setItem(key, JSON.stringify(v)); } catch { /* private window */ } };

export function useGridFilters<T>(id: string, cols: Col<T>[], rows: T[], extra?: ReactNode) {
    const [filters, setFilters] = useState<Filter[]>(() => load(`kk.grid.${id}.filters`, []));
    const [q, setQ] = useState('');
    useEffect(() => save(`kk.grid.${id}.filters`, filters), [id, filters]);
    const val = (c: Col<T>, r: T) => (c.value ? c.value(r) : (r as Record<string, unknown>)[c.key] as string | number | null);
    const out = useMemo(() => rows.filter((r) => filters.every((f) => { const c = cols.find((x) => x.key === f.col); return !c || test(val(c, r), c.type ?? 'text', f); })
        && (!q || cols.some((c) => String(val(c, r) ?? '').toLowerCase().includes(q.toLowerCase())))), [rows, filters, q, cols]); // eslint-disable-line react-hooks/exhaustive-deps
    const bar = <FilterBar cols={cols} rows={rows} filters={filters} setFilters={setFilters} q={q} setQ={setQ} shown={out.length} total={rows.length} extra={extra} />;

    return { rows: out, bar, filters };
}

function FilterBar<T>({ cols, rows, filters, setFilters, q, setQ, shown, total, extra }: { cols: Col<T>[]; rows: T[]; filters: Filter[]; setFilters: (f: Filter[]) => void; q: string; setQ: (s: string) => void; shown: number; total: number; extra?: ReactNode }) {
    const set = (id: number, patch: Partial<Filter>) => setFilters(filters.map((f) => (f.id === id ? { ...f, ...patch } : f)));
    const add = () => { const c = cols[0]; setFilters([...filters, { id: Date.now(), col: c.key, op: OPS[c.type ?? 'text'][0][0], a: '', b: '' }]); };
    const field = 'h-9 rounded-md border border-rule bg-tile px-2 text-sm text-ink focus:border-cobalt-600 focus:outline-none';
    return (
        <div className="space-y-2 border-b border-rule bg-glaze/50 px-3 py-3">
            <div className="flex flex-wrap items-center gap-2">
                <label className="relative min-w-52 flex-1 sm:max-w-xs"><Search size={15} aria-hidden className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-ink-soft" />
                    <input className={cx(field, 'w-full ps-8')} placeholder="Search every column" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search" /></label>
                <button type="button" onClick={add} className="inline-flex h-9 items-center gap-1.5 rounded-md border border-cobalt-800 bg-tile px-3 text-sm font-semibold text-cobalt-800 hover:bg-cobalt-800 hover:text-white"><Plus size={15} aria-hidden /> Add filter</button>
                {filters.length || q ? <button type="button" onClick={() => { setFilters([]); setQ(''); }} className="h-9 px-2 text-sm font-semibold text-ink-soft hover:text-crit-700">Clear all</button> : null}
                <span className="ms-auto text-sm text-ink-soft"><b className="text-ink tabular-nums">{shown.toLocaleString('en-PK')}</b> of {total.toLocaleString('en-PK')} rows{filters.length ? ` · ${filters.length} ${filters.length === 1 ? 'filter' : 'filters'}` : ''}</span>
                {extra}
            </div>
            {filters.map((f, i) => {
                const c = cols.find((x) => x.key === f.col) ?? cols[0];
                const type = c.type ?? 'text';
                const input = type === 'number' ? 'number' : type === 'date' && f.op !== 'last' ? 'date' : f.op === 'last' ? 'number' : 'text';
                const options = type === 'enum' ? [...new Set(rows.map((r) => String((c.value ? c.value(r) : (r as Record<string, unknown>)[c.key]) ?? '')).filter(Boolean))].sort() : [];
                return (
                    <div key={f.id} className="flex flex-wrap items-center gap-2">
                        <span className="inline-flex h-9 w-14 items-center justify-center rounded-md bg-cobalt-100 text-xs font-semibold text-cobalt-800"><Filter size={13} aria-hidden className="me-1" />{i ? 'and' : 'where'}</span>
                        <select className={field} value={f.col} aria-label="Column" onChange={(e) => { const nc = cols.find((x) => x.key === e.target.value)!; set(f.id, { col: nc.key, op: OPS[nc.type ?? 'text'][0][0], a: '', b: '' }); }}>{cols.map((x) => <option key={x.key} value={x.key}>{x.label}</option>)}</select>
                        <select className={field} value={f.op} aria-label="Condition" onChange={(e) => set(f.id, { op: e.target.value })}>{OPS[type].map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
                        {NO_VALUE.includes(f.op) ? null : type === 'enum' ? (
                            <select className={cx(field, 'min-w-40')} value={f.a} aria-label="Value" onChange={(e) => set(f.id, { a: e.target.value })}><option value="">Choose…</option>{options.map((o) => <option key={o} value={o}>{o}</option>)}</select>
                        ) : <input className={cx(field, 'w-40')} type={input} value={f.a} aria-label="Value" placeholder={f.op === 'between' ? 'from' : 'value'} onChange={(e) => set(f.id, { a: e.target.value })} />}
                        {f.op === 'between' ? <><span className="text-sm text-ink-soft">and</span><input className={cx(field, 'w-40')} type={input} value={f.b} aria-label="Up to" placeholder="to" onChange={(e) => set(f.id, { b: e.target.value })} /></> : null}
                        <button type="button" onClick={() => setFilters(filters.filter((x) => x.id !== f.id))} aria-label="Remove this filter" className="grid size-9 place-items-center rounded-md text-ink-soft hover:bg-crit-100 hover:text-crit-700"><X size={16} /></button>
                    </div>
                );
            })}
        </div>
    );
}

/** Export exactly these rows and columns as a file Excel opens (UTF-8 CSV). */
export function exportCsv<T>(name: string, cols: Col<T>[], rows: T[]) {
    const cell = (v: unknown) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; };
    const lines = [cols.map((c) => cell(c.label)).join(','), ...rows.map((r) => cols.map((c) => cell(c.value ? c.value(r) : (r as Record<string, unknown>)[c.key])).join(','))];
    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `${name} ${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

/** The grid itself. Pass rows already filtered (useGridFilters) or let it filter (filterable). */
export default function DataGrid<T>({ id, cols, rows, name, filterable = true, expand, rowKey, pageSize = 25, toolbar, empty = 'No rows' }: {
    id: string; cols: Col<T>[]; rows: T[]; name: string; filterable?: boolean; expand?: (row: T) => ReactNode; rowKey?: (row: T, i: number) => string; pageSize?: number; toolbar?: ReactNode; empty?: string;
}) {
    const [exportRows, setExportRows] = useState<T[]>([]);
    const exportBtn = <button type="button" onClick={() => exportCsv(name, cols, exportRows)} className="inline-flex h-9 items-center gap-1.5 rounded-md border border-ok-700 bg-tile px-3 text-sm font-semibold text-ok-700 hover:bg-ok-700 hover:text-white"><Download size={15} aria-hidden /> Export to Excel</button>;
    const own = useGridFilters(id, cols, rows, toolbar ? undefined : exportBtn);
    const list = filterable ? own.rows : rows;
    const [sort, setSort] = useState<{ key: string; dir: 1 | -1 } | null>(() => load(`kk.grid.${id}.sort`, null));
    useEffect(() => save(`kk.grid.${id}.sort`, sort), [id, sort]);
    const [open, setOpen] = useState<string | null>(null);
    const val = (c: Col<T>, r: T) => (c.value ? c.value(r) : (r as Record<string, unknown>)[c.key] as string | number | null);
    const sorted = useMemo(() => {
        const c = sort && cols.find((x) => x.key === sort.key);
        if (!c || !sort) return list;
        return [...list].sort((x, y) => {
            const a = val(c, x), b = val(c, y);
            if (a === b) return 0;
            if (a === null || a === undefined || a === '') return 1;
            if (b === null || b === undefined || b === '') return -1;
            return (typeof a === 'number' && typeof b === 'number' ? a - b : String(a).localeCompare(String(b), undefined, { numeric: true })) * sort.dir;
        });
    }, [list, sort, cols]); // eslint-disable-line react-hooks/exhaustive-deps
    const { rows: page, pager, page: at, size } = usePaged(sorted, { size: pageSize, id: `grid-${id}` });
    useEffect(() => setExportRows(sorted), [sorted]);
    const totals = cols.some((c) => c.total) ? cols.map((c) => {
        if (!c.total) return null;
        const nums = list.map((r) => val(c, r)).filter((v): v is number => typeof v === 'number' && !Number.isNaN(v));
        if (c.total === 'count') return list.filter((r) => { const v = val(c, r); return v !== null && v !== undefined && v !== ''; }).length.toLocaleString('en-PK');
        if (!nums.length) return '—';
        const n = c.total === 'sum' ? nums.reduce((s, v) => s + v, 0) : nums.reduce((s, v) => s + v, 0) / nums.length;
        return `${c.total === 'avg' ? 'avg ' : ''}${(Math.round(n * 10) / 10).toLocaleString('en-PK')}${c.unit ? ` ${c.unit}` : ''}`;
    }) : null;
    const num = (c: Col<T>) => c.type === 'number';
    const head = 'border border-rule bg-[#eef1ea] px-2.5 py-2 text-xs font-semibold whitespace-nowrap text-ink';
    const cell = 'border border-rule px-2.5 py-2 align-middle whitespace-nowrap';
    const key = (r: T, i: number) => (rowKey ? rowKey(r, i) : String(i + (at - 1) * size));
    return (
        <div>
            {filterable ? own.bar : null}
            {toolbar || !filterable ? <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">{toolbar ?? <span />}{exportBtn}</div> : null}
            {!sorted.length ? <p className="px-4 py-8 text-center text-sm text-ink-soft">{empty}{filterable && (own.filters.length) ? ': no row matches these filters.' : '.'}</p> : (
                <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-sm">
                        <thead><tr>
                            <th className={cx(head, 'w-12 text-center text-ink-soft')}>#</th>
                            {cols.map((c) => {
                                const on = sort?.key === c.key;
                                return (
                                    <th key={c.key} className={cx(head, num(c) ? 'text-end' : 'text-start')} style={c.width ? { width: c.width } : undefined} aria-sort={on ? (sort!.dir === 1 ? 'ascending' : 'descending') : 'none'}>
                                        <button type="button" className={cx('inline-flex items-center gap-1 hover:text-cobalt-800', num(c) && 'flex-row-reverse')} onClick={() => setSort(on && sort!.dir === -1 ? null : { key: c.key, dir: on ? -1 : 1 })}>
                                            {c.label}{on ? (sort!.dir === 1 ? <ArrowUp size={13} aria-hidden /> : <ArrowDown size={13} aria-hidden />) : <ArrowUpDown size={13} aria-hidden className="text-ink-faint" />}
                                        </button>
                                    </th>
                                );
                            })}
                        </tr></thead>
                        <tbody>{page.map((r, i) => {
                            const k = key(r, i);
                            const isOpen = open === k;
                            return (
                                <Fragment key={k}>
                                    <tr className={cx('hover:bg-cobalt-100/40', expand && 'cursor-pointer', isOpen && 'bg-cobalt-100/50')} onClick={expand ? () => setOpen(isOpen ? null : k) : undefined} aria-expanded={expand ? isOpen : undefined}>
                                        <td className={cx(cell, 'bg-[#f4f6f1] text-center text-xs text-ink-soft tabular-nums')}>{expand ? <span className="inline-flex items-center gap-0.5">{isOpen ? <ChevronDown size={13} aria-hidden /> : <ChevronRight size={13} aria-hidden />}{(at - 1) * size + i + 1}</span> : (at - 1) * size + i + 1}</td>
                                        {cols.map((c) => { const v = val(c, r); return <td key={c.key} className={cx(cell, num(c) ? 'text-end tabular-nums whitespace-nowrap' : 'text-start', c.type === 'date' && 'whitespace-nowrap')}>{c.render ? c.render(r) : v === null || v === undefined || v === '' ? <span className="text-ink-faint">—</span> : String(v)}</td>; })}
                                    </tr>
                                    {isOpen && expand ? <tr><td colSpan={cols.length + 1} className="border border-rule bg-glaze/40 p-3">{expand(r)}</td></tr> : null}
                                </Fragment>
                            );
                        })}</tbody>
                        {totals ? <tfoot><tr>
                            <td className={cx(cell, 'bg-[#eef1ea] text-center text-xs font-semibold text-ink-soft')}>Σ</td>
                            {totals.map((t, i) => <td key={cols[i].key} className={cx(cell, 'bg-[#eef1ea] font-semibold tabular-nums whitespace-nowrap', num(cols[i]) ? 'text-end' : 'text-start')}>{t ?? (i === 0 ? `${list.length.toLocaleString('en-PK')} rows` : '')}</td>)}
                        </tr></tfoot> : null}
                    </table>
                </div>
            )}
            {pager}
        </div>
    );
}
