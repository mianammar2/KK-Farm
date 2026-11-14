import { ArrowDown, ArrowUp, ArrowUpDown, ChevronDown, ChevronRight, Download, Filter, ListFilter, Search, SlidersHorizontal, X } from 'lucide-react';
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
    wrap?: boolean; // long text wraps instead of widening the table
};
type Filter = { id: number; col: string; op: string; a: string; b: string; quick?: boolean }; // quick: set from a column heading (D-289)
export type GridFilterApi<T> = { filters: Filter[]; setFilters: (f: Filter[]) => void; source: T[]; cols: Col<T>[] };
const BLANK = '(blank)';

const OPS: Record<ColType, Array<[string, string]>> = {
    text: [['has', 'contains'], ['not_has', 'does not contain'], ['is', 'is'], ['not', 'is not'], ['starts', 'starts with'], ['empty', 'is empty'], ['filled', 'is not empty']],
    enum: [['is', 'is'], ['not', 'is not'], ['empty', 'is empty'], ['filled', 'is not empty']],
    number: [['eq', '='], ['ne', '≠'], ['gt', '>'], ['gte', '≥'], ['lt', '<'], ['lte', '≤'], ['between', 'between'], ['empty', 'is empty'], ['filled', 'is not empty']],
    date: [['on', 'on'], ['before', 'before'], ['after', 'after'], ['between', 'between'], ['last', 'in the last … days'], ['empty', 'is empty']],
};
const NO_VALUE = ['empty', 'filled'];

function test(v: string | number | null | undefined, type: ColType, f: Filter): boolean {
    const empty = v === null || v === undefined || v === '';
    if (f.op === 'in') { const list = JSON.parse(f.a || '[]') as string[]; return !list.length || list.includes(empty ? BLANK : String(v)); }
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

    const api: GridFilterApi<T> = { filters, setFilters, source: rows, cols };

    return { rows: out, bar, filters, api };
}

function FilterBar<T>({ cols, rows, filters, setFilters, q, setQ, shown, total, extra }: { cols: Col<T>[]; rows: T[]; filters: Filter[]; setFilters: (f: Filter[]) => void; q: string; setQ: (s: string) => void; shown: number; total: number; extra?: ReactNode }) {
    const set = (id: number, patch: Partial<Filter>) => setFilters(filters.map((f) => (f.id === id ? { ...f, ...patch } : f)));
    const add = () => { const c = cols[0]; setFilters([...filters, { id: Date.now(), col: c.key, op: OPS[c.type ?? 'text'][0][0], a: '', b: '' }]); };
    const quick = filters.filter((f) => f.quick);
    const field = 'h-9 rounded-md border border-rule bg-tile px-2 text-sm text-ink focus:border-cobalt-600 focus:outline-none';
    return (
        <div className="space-y-2 border-b border-rule bg-glaze/50 px-3 py-3">
            <div className="flex flex-wrap items-center gap-2">
                <label className="relative min-w-52 flex-1 sm:max-w-xs"><Search size={15} aria-hidden className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-ink-soft" />
                    <input className={cx(field, 'w-full ps-8')} placeholder="Search every column" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search" /></label>
                <span className="inline-flex items-center gap-1.5 text-sm text-ink-soft"><ListFilter size={15} aria-hidden className="text-cobalt-800" /> Filter: click <ListFilter size={13} aria-hidden /> in any column heading</span>
                <button type="button" onClick={add} className="inline-flex h-9 items-center gap-1.5 rounded-md border border-rule bg-tile px-3 text-sm text-cobalt-800 hover:bg-glaze"><SlidersHorizontal size={15} aria-hidden /> Advanced filter</button>
                {filters.length || q ? <button type="button" onClick={() => { setFilters([]); setQ(''); }} className="h-9 px-2 text-sm font-semibold text-ink-soft hover:text-crit-700">Clear all</button> : null}
                <span className="ms-auto text-sm text-ink-soft"><b className="text-ink tabular-nums">{shown.toLocaleString('en-PK')}</b> of {total.toLocaleString('en-PK')} rows{filters.length ? ` · ${filters.length} ${filters.length === 1 ? 'filter' : 'filters'}` : ''}</span>
                {extra}
            </div>
            {quick.length ? <div className="flex flex-wrap items-center gap-1.5">{quick.map((f) => {
                const c = cols.find((x) => x.key === f.col);
                return <span key={f.id} className="inline-flex items-center gap-1 rounded-full bg-cobalt-800 py-1 ps-3 pe-1 text-sm text-white">{c?.label ?? f.col}: {chipText(f)}
                    <button type="button" onClick={() => setFilters(filters.filter((x) => x.id !== f.id))} aria-label={`Remove the ${c?.label} filter`} className="grid size-6 place-items-center rounded-full hover:bg-white/20"><X size={14} /></button></span>;
            })}</div> : null}
            {filters.filter((f) => !f.quick).map((f, i) => {
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

const fmtIso = (d: string) => (d ? d.split('-').reverse().join('/') : '');
function chipText(f: Filter): string {
    if (f.op === 'in') { const l = JSON.parse(f.a || '[]') as string[]; return l.length > 3 ? `${l.slice(0, 3).join(', ')} +${l.length - 3}` : l.join(', '); }
    if (f.op === 'between') {
        const date = /^\d{4}-/.test(f.a) || /^\d{4}-/.test(f.b);
        const a = date ? fmtIso(f.a) : f.a, b = date ? fmtIso(f.b) : f.b;
        return a && b ? `${a} to ${b}` : a ? `from ${a}` : `up to ${b}`;
    }
    return f.a;
}

/** D-289: a column heading's filter, like Excel: tick values (with counts), or a lowest / highest number or date. */
function HeaderFilter<T>({ col, api, at, onClose }: { col: Col<T>; api: GridFilterApi<T>; at: { x: number; y: number }; onClose: () => void }) {
    const type = col.type ?? 'text';
    const current = api.filters.find((f) => f.quick && f.col === col.key);
    const val = (r: T) => (col.value ? col.value(r) : (r as Record<string, unknown>)[col.key] as string | number | null);
    const counts = useMemo(() => {
        if (type === 'number' || type === 'date') return [];
        const m = new Map<string, number>();
        for (const r of api.source) { const v = val(r); const k = v === null || v === undefined || v === '' ? BLANK : String(v); m.set(k, (m.get(k) ?? 0) + 1); }
        return [...m.entries()].sort((a, b) => (a[0] === BLANK ? 1 : b[0] === BLANK ? -1 : a[0].localeCompare(b[0], undefined, { numeric: true })));
    }, [api.source, col.key]); // eslint-disable-line react-hooks/exhaustive-deps
    const [picked, setPicked] = useState<string[]>(() => (current?.op === 'in' ? JSON.parse(current.a || '[]') : []));
    const [q, setQ] = useState('');
    const [lo, setLo] = useState(current?.op === 'between' ? current.a : '');
    const [hi, setHi] = useState(current?.op === 'between' ? current.b : '');
    const apply = () => {
        const rest = api.filters.filter((f) => !(f.quick && f.col === col.key));
        const range = type === 'number' || type === 'date';
        const f: Filter | null = range ? (lo || hi ? { id: Date.now(), col: col.key, op: 'between', a: lo, b: hi, quick: true } : null) : picked.length ? { id: Date.now(), col: col.key, op: 'in', a: JSON.stringify(picked), b: '', quick: true } : null;
        api.setFilters(f ? [...rest, f] : rest);
        onClose();
    };
    const shown = counts.filter(([k]) => !q || k.toLowerCase().includes(q.toLowerCase()));
    const field = 'h-9 w-full rounded-md border border-rule bg-tile px-2 text-sm text-ink focus:border-cobalt-600 focus:outline-none';
    const left = Math.max(8, Math.min(at.x, window.innerWidth - 296));
    return (
        <>
            <div className="fixed inset-0 z-40" onClick={onClose} aria-hidden />
            <div role="dialog" aria-label={`Filter ${col.label}`} className="fixed z-50 w-72 rounded-tile border border-rule bg-tile p-3 text-sm shadow-xl" style={{ left, top: Math.min(at.y + 4, window.innerHeight - 380) }}>
                <p className="mb-2 font-medium text-cobalt-900">{col.label}</p>
                {type === 'number' || type === 'date' ? (
                    <div className="grid grid-cols-2 gap-2">
                        <label className="text-xs text-ink-soft">{type === 'date' ? 'From' : 'Lowest'}<input className={field} type={type === 'date' ? 'date' : 'number'} value={lo} onChange={(e) => setLo(e.target.value)} autoFocus /></label>
                        <label className="text-xs text-ink-soft">{type === 'date' ? 'To' : 'Highest'}<input className={field} type={type === 'date' ? 'date' : 'number'} value={hi} onChange={(e) => setHi(e.target.value)} /></label>
                    </div>
                ) : <>
                    {counts.length > 8 ? <input className={cx(field, 'mb-2')} placeholder="Search values" value={q} onChange={(e) => setQ(e.target.value)} autoFocus /> : null}
                    <div className="mb-1 flex gap-3 text-xs"><button type="button" className="text-cobalt-800 hover:underline" onClick={() => setPicked([...new Set([...picked, ...shown.map(([k]) => k)])])}>Select all</button><button type="button" className="text-cobalt-800 hover:underline" onClick={() => setPicked([])}>Clear</button></div>
                    <div className="max-h-56 space-y-0.5 overflow-y-auto">{shown.map(([k, n]) => (
                        <label key={k} className="flex min-h-8 cursor-pointer items-center gap-2 rounded px-1 hover:bg-glaze">
                            <input type="checkbox" checked={picked.includes(k)} onChange={() => setPicked(picked.includes(k) ? picked.filter((x) => x !== k) : [...picked, k])} />
                            <span className={cx('flex-1 truncate', k === BLANK && 'text-ink-soft')}>{k}</span><span className="text-xs text-ink-soft tabular-nums">{n}</span>
                        </label>
                    ))}</div>
                </>}
                <div className="mt-3 flex gap-2">
                    <button type="button" onClick={apply} className="h-9 flex-1 rounded-md bg-cobalt-800 text-sm text-white hover:bg-cobalt-900">Apply</button>
                    {current ? <button type="button" onClick={() => { api.setFilters(api.filters.filter((f) => f !== current)); onClose(); }} className="h-9 rounded-md border border-rule px-3 text-sm text-ink-soft hover:text-crit-700">Remove</button> : null}
                </div>
            </div>
        </>
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
export default function DataGrid<T>({ id, cols, rows, name, filterable = true, expand, rowKey, pageSize = 25, toolbar, empty = 'No rows', filterApi }: {
    id: string; cols: Col<T>[]; rows: T[]; name: string; filterable?: boolean; expand?: (row: T) => ReactNode; rowKey?: (row: T, i: number) => string; pageSize?: number; toolbar?: ReactNode; empty?: string;
    filterApi?: GridFilterApi<T>; // filters kept by the page (useGridFilters) that the column headings set
}) {
    const [headFilter, setHeadFilter] = useState<{ key: string; x: number; y: number } | null>(null);
    const [exportRows, setExportRows] = useState<T[]>([]);
    const exportBtn = <button type="button" onClick={() => exportCsv(name, cols, exportRows)} className="inline-flex h-9 items-center gap-1.5 rounded-md border border-ok-700 bg-tile px-3 text-sm font-semibold text-ok-700 hover:bg-ok-700 hover:text-white"><Download size={15} aria-hidden /> Export to Excel</button>;
    const own = useGridFilters(id, cols, rows, toolbar ? undefined : exportBtn);
    const list = filterable ? own.rows : rows;
    const api = filterable ? own.api : filterApi;
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
    const head = 'border border-rule bg-[#eef1ea] px-2.5 py-2 text-xs font-medium whitespace-nowrap text-ink';
    const cell = 'border border-rule px-2.5 py-2 align-middle';
    const key = (r: T, i: number) => (rowKey ? rowKey(r, i) : String(i + (at - 1) * size));
    return (
        <div>
            {filterable ? own.bar : null}
            {toolbar || !filterable ? <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">{toolbar ?? <span />}{exportBtn}</div> : null}
            {!sorted.length ? <p className="px-4 py-8 text-center text-sm text-ink-soft">{empty}{filterable && (own.filters.length) ? ': no row matches these filters.' : '.'}</p> : (
                <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-sm [&_td]:font-normal [&_td_*]:!font-normal">
                        <thead><tr>
                            <th className={cx(head, 'w-12 text-center text-ink-soft')}>#</th>
                            {cols.map((c) => {
                                const on = sort?.key === c.key;
                                return (
                                    <th key={c.key} className={cx(head, num(c) ? 'text-end' : 'text-start')} style={c.width ? { width: c.width } : undefined} aria-sort={on ? (sort!.dir === 1 ? 'ascending' : 'descending') : 'none'}>
                                        <span className={cx('inline-flex items-center gap-1', num(c) && 'flex-row-reverse')}>
                                            <button type="button" className={cx('inline-flex items-center gap-1 hover:text-cobalt-800', num(c) && 'flex-row-reverse')} onClick={() => setSort(on && sort!.dir === -1 ? null : { key: c.key, dir: on ? -1 : 1 })}>
                                                {c.label}{on ? (sort!.dir === 1 ? <ArrowUp size={13} aria-hidden /> : <ArrowDown size={13} aria-hidden />) : <ArrowUpDown size={13} aria-hidden className="text-ink-faint" />}
                                            </button>
                                            {api ? (() => {
                                                const active = api.filters.some((f) => f.quick && f.col === c.key);
                                                return <button type="button" aria-label={`Filter ${c.label}`} title={`Filter ${c.label}`} onClick={(e) => { const r = e.currentTarget.getBoundingClientRect(); setHeadFilter({ key: c.key, x: r.left, y: r.bottom }); }}
                                                    className={cx('grid size-6 place-items-center rounded', active ? 'bg-cobalt-800 text-white' : 'text-ink-soft hover:bg-white hover:text-cobalt-800')}><ListFilter size={13} aria-hidden /></button>;
                                            })() : null}
                                        </span>
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
                                        {cols.map((c) => { const v = val(c, r); return <td key={c.key} className={cx(cell, num(c) ? 'text-end tabular-nums' : 'text-start', c.wrap ? 'min-w-[14rem] max-w-[28rem] whitespace-normal' : 'whitespace-nowrap')}>{c.render ? c.render(r) : v === null || v === undefined || v === '' ? <span className="text-ink-faint">—</span> : String(v)}</td>; })}
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
            {headFilter && api ? (() => { const c = api.cols.find((x) => x.key === headFilter.key) ?? cols.find((x) => x.key === headFilter.key); return c ? <HeaderFilter col={c} api={api} at={headFilter} onClose={() => setHeadFilter(null)} /> : null; })() : null}
        </div>
    );
}
