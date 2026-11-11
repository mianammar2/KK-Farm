import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Download, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button, Chip, Empty, PageHeader, Panel, Stat, StatRow } from '../components/ui';

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
    const [q, setQ] = useState('');
    const [batch, setBatch] = useState('');
    const [pen, setPen] = useState('');
    const [show, setShow] = useState<'all' | 'gain' | 'loss' | 'same' | 'none'>('all');
    const [sort, setSort] = useState<{ key: SortKey; dir: 1 | -1 }>({ key: 'number', dir: 1 });
    const batches = useMemo(() => [...new Set(rows.map((r) => r.batch))].sort(), [rows]);
    const pens = useMemo(() => [...new Set(rows.map((r) => r.pen).filter((x): x is string => !!x))].sort(), [rows]);
    const diff = (r: Row) => (mode === 'prev' ? r.sincePrev : mode === 'period' ? r.period : r.total);
    const from = (r: Row) => (mode === 'prev' ? r.prev : mode === 'period' ? r.base : r.first);

    const list = useMemo(() => {
        const t = q.trim().toLowerCase();
        const out = rows.filter((r) => {
            const d = diff(r).kg;
            return (!t || [r.number, r.animalId, r.breed].some((v) => v?.toLowerCase().includes(t)))
                && (!batch || r.batch === batch) && (!pen || r.pen === pen)
                && (show === 'all' || (show === 'none' ? d === null : show === 'gain' ? d !== null && d > 0 : show === 'loss' ? d !== null && d < 0 : d === 0));
        });
        const val = (r: Row): number | string => (sort.key === 'number' ? r.number : sort.key === 'latest' ? (r.latest?.kg ?? -1) : sort.key === 'kg' ? (diff(r).kg ?? -999) : (diff(r).adg ?? -99999));
        return out.sort((a, b) => { const x = val(a); const y = val(b); return (x < y ? -1 : x > y ? 1 : 0) * sort.dir; });
    }, [rows, q, batch, pen, show, sort, mode]); // eslint-disable-line react-hooks/exhaustive-deps

    const head = (key: SortKey, label: string, cls = '') => (
        <th className={cls}><button type="button" className="inline-flex items-center gap-1 font-semibold" onClick={() => setSort((s) => ({ key, dir: s.key === key ? (s.dir === 1 ? -1 : 1) : key === 'number' ? 1 : -1 }))}>
            {label}{sort.key === key ? (sort.dir === 1 ? <ArrowUp size={13} aria-hidden /> : <ArrowDown size={13} aria-hidden />) : null}
        </button></th>
    );
    const csv = () => {
        const lines = [['Goat', 'Animal ID', 'Batch', 'Breed', 'Pen', 'From KG', 'From date', 'Latest KG', 'Latest date', 'Change KG', 'Days', 'Grams a day'].join(',')];
        list.forEach((r) => { const f = from(r); const d = diff(r); lines.push([r.number, r.animalId ?? '', r.batch, r.breed ?? '', r.pen ?? '', f?.kg ?? '', f?.on ?? '', r.latest?.kg ?? '', r.latest?.on ?? '', d.kg ?? '', d.days ?? '', d.adg ?? ''].map((v) => `"${String(v).replace(/"/g, '""')}"`).join(',')); });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/csv' }));
        a.download = `weight-gain-loss-${MODES[mode].toLowerCase().replace(/\s+/g, '-')}.csv`;
        a.click();
    };
    const shown = list.filter((r) => diff(r).kg !== null);
    const avg = shown.length ? Math.round(shown.reduce((n, r) => n + (diff(r).adg ?? 0), 0) / shown.length) : null;

    return (
        <>
            <Head title="Weight Gain & Loss" />
            <PageHeader title="Weight Gain & Loss" description="Every goat's latest weight against its previous weighing, a date you choose, or its first weighing. Held readings waiting for review are not counted." actions={<Button variant="secondary" onClick={csv}><Download size={16} aria-hidden /> Download CSV</Button>} />
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
                <div className="mt-3 grid gap-2 sm:grid-cols-[1fr_auto_auto_auto]">
                    <label className="relative"><Search size={15} aria-hidden className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-faint" /><input className="field pl-8" placeholder="Goat, Animal ID or breed" value={q} onChange={(e) => setQ(e.target.value)} /></label>
                    <select className="field" aria-label="Batch" value={batch} onChange={(e) => setBatch(e.target.value)}><option value="">All batches</option>{batches.map((b) => <option key={b} value={b}>{b}</option>)}</select>
                    <select className="field" aria-label="Pen" value={pen} onChange={(e) => setPen(e.target.value)}><option value="">All pens</option>{pens.map((p) => <option key={p} value={p}>{p}</option>)}</select>
                    <select className="field" aria-label="Show" value={show} onChange={(e) => setShow(e.target.value as typeof show)}><option value="all">All goats</option><option value="gain">Gaining</option><option value="loss">Losing</option><option value="same">No change</option><option value="none">Not enough weighings</option></select>
                </div>
            </Panel>

            <Panel flush title={`${list.length} goats`} description={avg === null ? undefined : `Average ${avg > 0 ? '+' : ''}${avg} g a day ${MODES[mode].toLowerCase()}`}>
                {list.length ? (
                    <div className="overflow-x-auto"><table className="data-table min-w-[760px]">
                        <thead><tr>{head('number', 'Goat')}<th>Batch · Pen</th><th className="text-right">From</th>{head('latest', 'Latest', 'text-right')}{head('kg', 'Change', 'text-right')}<th className="text-right">Days</th>{head('adg', 'g / day', 'text-right')}</tr></thead>
                        <tbody>{list.map((r) => {
                            const d = diff(r); const f = from(r);
                            return (
                                <tr key={r.id}>
                                    <td><Link href={`/goats/${r.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{r.number}</Link>{r.held ? <Chip className="ml-1.5 bg-watch-100 text-watch-700">{r.held} held</Chip> : null}<div className="text-xs text-ink-faint">{[r.animalId, r.breed].filter(Boolean).join(' · ')}</div></td>
                                    <td className="text-sm">{r.batch}<div className="text-xs text-ink-faint">{r.pen ?? 'No pen'}</div></td>
                                    <td className="text-right">{f && d.kg !== null ? <>{f.kg} KG<div className="text-xs text-ink-faint">{f.on}</div></> : '—'}</td>
                                    <td className="text-right">{r.latest ? <>{r.latest.kg} KG<div className="text-xs text-ink-faint">{r.latest.on}</div></> : <span className="text-ink-faint">Not weighed</span>}</td>
                                    <td className={`text-right font-semibold ${tone(d.kg)}`}>{fmt(d.kg, ' KG')}</td>
                                    <td className="text-right text-ink-soft">{d.days ?? '—'}</td>
                                    <td className={`text-right font-semibold ${tone(d.adg)}`}>{fmt(d.adg, ' g')}</td>
                                </tr>
                            );
                        })}</tbody>
                    </table></div>
                ) : <Empty title="No goats match" />}
            </Panel>
        </>
    );
}
