import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import DataGrid, { type Col } from '../components/DataGrid';
import { Chip, PageHeader, Panel, Stat, StatRow } from '../components/ui';

type Pt = { kg: number; on: string } | null;
type Diff = { kg: number | null; days: number | null; adg: number | null };
type Row = { id: string; number: string; animalId: string | null; batch: string; breed: string | null; sex: string; pen: string | null; count: number; held: number; first: Pt; prev: Pt; latest: Pt; base: Pt; sincePrev: Diff; period: Diff; total: Diff };
type Props = { rows: Row[]; since: string; kpi: { weighed: number; goats: number; gaining: number; losing: number; avgAdg: number | null; periodAdg: number | null; held: number } };
type Mode = 'prev' | 'period' | 'total';
type SortKey = 'number' | 'latest' | 'kg' | 'adg';

const MODES: Record<Mode, string> = { prev: 'Since previous weighing', period: 'Since a date', total: 'Since first weighing' };
const fmt = (n: number | null, unit = '') => (n === null ? '—' : `${n > 0 ? '+' : ''}${n}${unit}`);
const tone = (n: number | null) => (n === null ? 'text-ink-faint' : n > 0 ? 'text-ok-700' : n < 0 ? 'text-crit-700' : 'text-ink-soft');

/** D-216: Dashboard → Weight Gain & Loss, per goat. */
export default function WeightChange({ rows, since, kpi }: Props) {
    const [mode, setMode] = useState<Mode>('prev');
    const diff = (r: Row) => (mode === 'prev' ? r.sincePrev : mode === 'period' ? r.period : r.total);
    const from = (r: Row) => (mode === 'prev' ? r.prev : mode === 'period' ? r.base : r.first);
    // D-273: a spreadsheet: sort, filter by anything (gaining, losing, breed, pen, batch …), totals, export
    const cols = useMemo<Col<Row>[]>(() => [
        { key: 'number', label: 'Goat', type: 'text', total: 'count', render: (r) => <span className="inline-flex items-center gap-1.5"><Link href={`/goats/${r.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{r.number}</Link>{r.held ? <Chip className="bg-watch-100 text-watch-700">{r.held} held</Chip> : null}</span> },
        { key: 'animalId', label: 'Animal ID', type: 'text' },
        { key: 'breed', label: 'Breed', type: 'enum' },
        { key: 'sex', label: 'Sex', type: 'enum', value: (r) => (r.sex ? r.sex.charAt(0).toUpperCase() + r.sex.slice(1) : null) },
        { key: 'batch', label: 'Batch', type: 'enum' },
        { key: 'pen', label: 'Pen', type: 'enum' },
        { key: 'fromKg', label: 'From (KG)', type: 'number', value: (r) => (diff(r).kg !== null ? from(r)?.kg ?? null : null), total: 'avg', unit: 'KG' },
        { key: 'fromOn', label: 'From date', type: 'text', value: (r) => (diff(r).kg !== null ? from(r)?.on ?? null : null) },
        { key: 'latestKg', label: 'Latest (KG)', type: 'number', value: (r) => r.latest?.kg ?? null, total: 'avg', unit: 'KG' },
        { key: 'latestOn', label: 'Latest date', type: 'text', value: (r) => r.latest?.on ?? null },
        { key: 'kg', label: 'Change (KG)', type: 'number', value: (r) => diff(r).kg, total: 'avg', unit: 'KG', render: (r) => <span className={`font-semibold ${tone(diff(r).kg)}`}>{fmt(diff(r).kg, ' KG')}</span> },
        { key: 'days', label: 'Days', type: 'number', value: (r) => diff(r).days },
        { key: 'adg', label: 'g / day', type: 'number', value: (r) => diff(r).adg, total: 'avg', unit: 'g', render: (r) => <span className={`font-semibold ${tone(diff(r).adg)}`}>{fmt(diff(r).adg, ' g')}</span> },
        { key: 'trend', label: 'Trend', type: 'enum', value: (r) => { const d = diff(r).kg; return d === null ? 'Not enough weighings' : d > 0 ? 'Gaining' : d < 0 ? 'Losing' : 'No change'; } },
    ], [mode]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <>
            <Head title="Weight Gain & Loss" />
            <PageHeader title="Weight Gain & Loss" description="Every goat's latest weight against its previous weighing, a date you choose, or its first weighing. Held readings waiting for review are not counted." />
            <StatRow>
                <Stat label="Goats weighed" value={`${kpi.weighed} of ${kpi.goats}`} />
                <Stat label="Gaining" value={kpi.gaining} note="Since previous weighing" tone={kpi.gaining ? 'good' : undefined} />
                <Stat label="Losing" value={kpi.losing} note="Since previous weighing" tone={kpi.losing ? 'alert' : undefined} />
                <Stat label="Average daily gain" value={kpi.avgAdg === null ? '—' : `${kpi.avgAdg} g`} note="Since previous weighing" />
                <Stat label="Held for review" value={kpi.held} tone={kpi.held ? 'alert' : undefined} note="Growth → Held Weights" />
            </StatRow>

            <Panel className="mb-4">
                <div className="flex flex-wrap items-end gap-2">
                    <div role="radiogroup" aria-label="Compare" className="flex flex-wrap gap-1">{(Object.keys(MODES) as Mode[]).map((m) => (
                        <button key={m} type="button" role="radio" aria-checked={mode === m} onClick={() => setMode(m)} className={mode === m ? 'rounded-full bg-cobalt-800 px-3 py-1.5 text-xs font-semibold text-white' : 'rounded-full bg-glaze px-3 py-1.5 text-xs font-semibold ring-1 ring-rule'}>{MODES[m]}</button>
                    ))}</div>
                    {mode === 'period' ? <label className="text-xs font-semibold text-ink-soft">From <input type="date" className="field ml-1 inline-block w-auto py-1" value={since} max={new Date().toISOString().slice(0, 10)} onChange={(e) => e.target.value && router.get('/dashboard/weight-change', { since: e.target.value }, { preserveState: true, preserveScroll: true, replace: true })} /></label> : null}
                </div>
            </Panel>

            <Panel flush title={MODES[mode]}>
                <DataGrid<Row> id={`weight-change-${mode}`} name={`Weight gain and loss ${MODES[mode].toLowerCase()}`} cols={cols} rows={rows} rowKey={(r) => r.id} pageSize={50} empty="No goats" />
            </Panel>
        </>
    );
}
