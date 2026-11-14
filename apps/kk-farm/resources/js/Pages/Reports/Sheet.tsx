import { Head, Link } from '@inertiajs/react';
import { Columns3, Layers } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import DataGrid, { useGridFilters, type Col } from '../../components/DataGrid';
import { cx, PageHeader, Panel } from '../../components/ui';

/**
 * D-288: the Herd Sheet: every goat, everything known about it, in one spreadsheet. Filter on any column (as many
 * filters as needed), choose the columns, group by any column for a summary per group, export what is shown.
 */
type Row = Record<string, string | number | null> & { id: string; tag: string };
type Props = { rows: Row[]; seeHealth: boolean };

type Def = Col<Row> & { section: string; agg?: 'sum' | 'avg' };
const goatLink = (key: string) => (r: Row) => (r[key] && r[`${key}Id`] ? <Link href={`/goats/${r[`${key}Id`]}`} className="font-mono text-cobalt-800 hover:underline">{r[key]}</Link> : '—');
const dated = (iso: string, shown: string): Partial<Def> => ({ key: iso, type: 'date', render: (r) => (r[shown] as string) ?? '—' });

const DEFS: Def[] = [
    { section: 'Identity', key: 'tag', label: 'Tag ID', type: 'text', total: 'count', render: (r) => <Link href={`/goats/${r.id}`} className="font-mono text-cobalt-800 hover:underline">{r.tag}</Link> },
    { section: 'Identity', key: 'name', label: 'Name', type: 'text' },
    { section: 'Identity', key: 'rfid', label: 'RFID tag', type: 'text' },
    { section: 'Identity', key: 'breed', label: 'Breed', type: 'enum' },
    { section: 'Identity', key: 'sex', label: 'Sex', type: 'enum' },
    { section: 'Origin', key: 'origin', label: 'Entry type', type: 'enum' },
    { section: 'Origin', ...dated('arrivedIso', 'arrived'), label: 'Arrived / born on' } as Def,
    { section: 'Origin', key: 'from', label: 'From farm', type: 'enum' },
    { section: 'Origin', ...dated('bornIso', 'born'), label: 'Birth date' } as Def,
    { section: 'Origin', key: 'bornEstimated', label: 'Birth date estimated', type: 'enum' },
    { section: 'Origin', key: 'ageMonths', label: 'Age (months)', type: 'number', total: 'avg', agg: 'avg' },
    { section: 'Classification', key: 'code', label: 'Class code', type: 'enum' },
    { section: 'Classification', key: 'stage', label: 'Age stage', type: 'enum' },
    { section: 'Classification', key: 'role', label: 'Role', type: 'enum' },
    { section: 'Classification', key: 'purpose', label: 'Purpose', type: 'enum' },
    { section: 'Classification', key: 'shed', label: 'Shed', type: 'enum' },
    { section: 'Classification', key: 'pen', label: 'PEN', type: 'enum' },
    { section: 'Classification', key: 'status', label: 'Status', type: 'enum' },
    { section: 'Growth', key: 'weighings', label: 'Weighings', type: 'number', total: 'sum', agg: 'sum' },
    { section: 'Growth', key: 'firstKg', label: 'First weight (KG)', type: 'number', total: 'avg', agg: 'avg' },
    { section: 'Growth', key: 'latestKg', label: 'Latest weight (KG)', type: 'number', total: 'avg', agg: 'avg' },
    { section: 'Growth', ...dated('latestOnIso', 'latestOn'), label: 'Last weighed' } as Def,
    { section: 'Growth', key: 'gainKg', label: 'Gain (KG)', type: 'number', total: 'avg', agg: 'avg', render: (r) => (r.gainKg === null ? '—' : <span className={Number(r.gainKg) > 0 ? 'text-ok-700' : Number(r.gainKg) < 0 ? 'text-crit-700' : ''}>{Number(r.gainKg) > 0 ? '+' : ''}{r.gainKg}</span>) },
    { section: 'Growth', key: 'adg', label: 'Daily gain (g)', type: 'number', total: 'avg', agg: 'avg' },
    { section: 'Growth', key: 'bcs', label: 'Body score', type: 'number', total: 'avg', agg: 'avg' },
    { section: 'Growth', key: 'heightIn', label: 'Height (in)', type: 'number', total: 'avg', agg: 'avg' },
    { section: 'Health', key: 'health', label: 'Health', type: 'enum' },
    { section: 'Health', key: 'tempF', label: 'Last temp (°F)', type: 'number', total: 'avg', agg: 'avg' },
    { section: 'Health', ...dated('lastCheckIso', 'lastCheck'), label: 'Last checkup' } as Def,
    { section: 'Health', key: 'checkups', label: 'Checkups', type: 'number', total: 'sum', agg: 'sum' },
    { section: 'Health', key: 'cases', label: 'Health cases', type: 'number', total: 'sum', agg: 'sum' },
    { section: 'Health', key: 'openCases', label: 'Open cases', type: 'number', total: 'sum', agg: 'sum' },
    { section: 'Health', key: 'lastCondition', label: 'Last condition', type: 'enum' },
    { section: 'Health', key: 'treatments', label: 'Treatments', type: 'number', total: 'sum', agg: 'sum' },
    { section: 'Health', key: 'lastMedicine', label: 'Last medicine', type: 'enum' },
    { section: 'Health', key: 'meatClear', label: 'Meat clear on', type: 'text' },
    { section: 'Health', key: 'milkClear', label: 'Milk clear on', type: 'text' },
    { section: 'Breeding', key: 'repro', label: 'Breeding status', type: 'enum' },
    { section: 'Breeding', key: 'dam', label: 'Mother', type: 'text', render: goatLink('dam') },
    { section: 'Breeding', key: 'sire', label: 'Father', type: 'text', render: goatLink('sire') },
    { section: 'Breeding', key: 'kids', label: 'Kids (own)', type: 'number', total: 'sum', agg: 'sum' },
    { section: 'Breeding', key: 'matings', label: 'Matings', type: 'number', total: 'sum', agg: 'sum' },
    { section: 'Breeding', key: 'kiddings', label: 'Kiddings', type: 'number', total: 'sum', agg: 'sum' },
    { section: 'Breeding', key: 'bornAlive', label: 'Born alive', type: 'number', total: 'sum', agg: 'sum' },
    { section: 'Breeding', key: 'stillborn', label: 'Stillborn', type: 'number', total: 'sum', agg: 'sum' },
    { section: 'Breeding', ...dated('lastKiddingIso', 'lastKidding'), label: 'Last kidding' } as Def,
    { section: 'Movement & exit', key: 'penMoves', label: 'PEN moves', type: 'number', total: 'sum', agg: 'sum' },
    { section: 'Movement & exit', ...dated('exitIso', 'exitOn'), label: 'Left on' } as Def,
    { section: 'Movement & exit', key: 'exitReason', label: 'Exit reason', type: 'enum' },
    { section: 'Movement & exit', key: 'buyer', label: 'Buyer / destination', type: 'text' },
    { section: 'Movement & exit', key: 'salePkr', label: 'Sale (PKR)', type: 'number', total: 'sum', agg: 'sum' },
    { section: 'Record', ...dated('enteredIso', 'entered'), label: 'Entered on' } as Def,
    { section: 'Record', key: 'enteredBy', label: 'Entered by', type: 'enum' },
];
const HEALTH = new Set(['tempF', 'lastCheckIso', 'checkups', 'cases', 'openCases', 'lastCondition', 'treatments', 'lastMedicine', 'meatClear', 'milkClear']);
const ESSENTIAL = ['tag', 'breed', 'sex', 'origin', 'arrivedIso', 'ageMonths', 'code', 'role', 'pen', 'status', 'health', 'latestKg', 'gainKg', 'adg', 'repro', 'kids'];
const load = <V,>(k: string, f: V): V => { try { const v = localStorage.getItem(k); return v ? (JSON.parse(v) as V) : f; } catch { return f; } };
const save = (k: string, v: unknown) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch { /* private window */ } };

type Group = { key: string; value: string; count: number; list: Row[] } & Record<string, number | string | null | Row[]>;

export default function Sheet({ rows, seeHealth }: Props) {
    const defs = useMemo(() => DEFS.filter((c) => seeHealth || !HEALTH.has(c.key)), [seeHealth]);
    const [shown, setShown] = useState<string[]>(() => load('kk.sheet.cols', ESSENTIAL));
    const [groupBy, setGroupBy] = useState<string>(() => load('kk.sheet.group', ''));
    const [picking, setPicking] = useState(false);
    useEffect(() => save('kk.sheet.cols', shown), [shown]);
    useEffect(() => save('kk.sheet.group', groupBy), [groupBy]);
    const { rows: filtered, bar, api } = useGridFilters('herd-sheet', defs, rows);
    const visible = defs.filter((c) => shown.includes(c.key));
    const sections = [...new Set(defs.map((c) => c.section))];
    const groupCol = defs.find((c) => c.key === groupBy);

    // Group by: one row per value, with the count and the sum or average of every number column shown
    const nums = visible.filter((c) => c.agg);
    const groups = useMemo<Group[]>(() => {
        if (!groupCol) return [];
        const m = new Map<string, Row[]>();
        for (const r of filtered) {
            const v = String((groupCol.value ? groupCol.value(r) : r[groupCol.key]) ?? '(blank)');
            m.set(v, [...(m.get(v) ?? []), r]);
        }
        return [...m.entries()].map(([value, list]) => {
            const g: Group = { key: value, value, count: list.length, list };
            for (const c of nums) {
                const xs = list.map((r) => r[c.key]).filter((x): x is number => typeof x === 'number');
                g[c.key] = xs.length ? Math.round((c.agg === 'sum' ? xs.reduce((s, x) => s + x, 0) : xs.reduce((s, x) => s + x, 0) / xs.length) * 10) / 10 : null;
            }
            return g;
        }).sort((a, b) => b.count - a.count);
    }, [filtered, groupCol, nums]); // eslint-disable-line react-hooks/exhaustive-deps
    const groupCols: Col<Group>[] = groupCol ? [
        { key: 'value', label: groupCol.label, type: 'text', render: (g) => <span className="text-cobalt-900">{g.value}</span> },
        { key: 'count', label: 'Goats', type: 'number', total: 'sum' },
        { key: 'share', label: '% of shown', type: 'number', value: (g) => (filtered.length ? Math.round((g.count / filtered.length) * 1000) / 10 : null) },
        ...nums.map((c) => ({ key: c.key, label: `${c.agg === 'sum' ? 'Total' : 'Average'} ${c.label.toLowerCase()}`, type: 'number' as const, total: c.agg })),
    ] : [];
    const seg = (on: boolean) => cx('inline-flex h-9 items-center gap-1.5 rounded-md border px-3 text-sm', on ? 'border-cobalt-800 bg-cobalt-800 text-white' : 'border-rule bg-tile text-cobalt-800 hover:bg-glaze');

    const toolbar = (
                <div className="flex flex-wrap items-center gap-2">
                    <button type="button" className={seg(picking)} onClick={() => setPicking(!picking)} aria-expanded={picking}><Columns3 size={15} aria-hidden /> Columns ({visible.length} of {defs.length})</button>
                    <label className="inline-flex items-center gap-2 text-sm text-ink-soft"><Layers size={15} aria-hidden /> Group by
                        <select className="h-9 rounded-md border border-rule bg-tile px-2 text-sm text-ink" value={groupBy} onChange={(e) => setGroupBy(e.target.value)}>
                            <option value="">No grouping (one row per goat)</option>
                            {defs.filter((c) => c.type === 'enum' || c.type === 'text').filter((c) => !['tag', 'name', 'rfid'].includes(c.key)).map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
                        </select>
                    </label>
                </div>
    );

    return (
        <>
            <Head title="Herd Sheet" />
            <PageHeader title="Herd Sheet" description="Every goat and everything recorded about it, in one sheet. Add filters on any column, choose the columns, group by any column for totals and averages, and export to Excel." />
            <Panel flush>
                {bar}
                {picking ? (
                    <div className="grid gap-4 border-b border-rule bg-glaze/40 px-3 py-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-4">
                            <button type="button" className={seg(false)} onClick={() => setShown(ESSENTIAL)}>Essentials</button>
                            <button type="button" className={seg(false)} onClick={() => setShown(defs.map((c) => c.key))}>All columns</button>
                        </div>
                        {sections.map((s) => (
                            <fieldset key={s}>
                                <legend className="mb-1 text-xs font-medium text-ink-soft">{s}</legend>
                                {defs.filter((c) => c.section === s).map((c) => (
                                    <label key={c.key} className="flex min-h-8 items-center gap-2 text-sm">
                                        <input type="checkbox" checked={shown.includes(c.key)} disabled={c.key === 'tag'} onChange={() => setShown(shown.includes(c.key) ? shown.filter((k) => k !== c.key) : [...shown, c.key])} /> {c.label}
                                    </label>
                                ))}
                            </fieldset>
                        ))}
                    </div>
                ) : null}
                {groupCol ? (
                    <DataGrid<Group> id={`herd-sheet-group-${groupBy}`} name={`Herd sheet by ${groupCol.label.toLowerCase()}`} cols={groupCols} rows={groups} filterable={false} rowKey={(g) => g.key} toolbar={toolbar}
                        expand={(g) => <DataGrid<Row> id="herd-sheet-group-rows" name={`Herd sheet ${groupCol.label} ${g.value}`} cols={visible} rows={g.list} filterable={false} rowKey={(r) => r.id} pageSize={50} />} />
                ) : (
                    <DataGrid<Row> id="herd-sheet" name="Herd sheet" cols={visible} rows={filtered} filterable={false} rowKey={(r) => r.id} pageSize={50} toolbar={toolbar} filterApi={api} />
                )}
            </Panel>
        </>
    );
}
