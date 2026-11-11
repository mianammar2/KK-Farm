import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type PenRow = { id: number; name: string; purpose: string | null; capacity: number | null; count: number; pct: number | null; over: boolean; status: string; reason: string | null; statusBy: string | null; lastClean: string | null };
type Cleaning = { id: number; pen: string | null; kind: string; started: string; finished: string | null; disinfectant: string | null; minutes: number | null; notes: string | null; by: string; verifiedBy: string | null };
type Props = {
    pens: PenRow[]; cleanings: Cleaning[]; overrides: Array<{ on: string; goat: string | null; pen: string | null; why: string }>;
    kpi: { full: number; over: number; closed: number; toVerify: number; occupancy: number | null };
    options: { kinds: Record<string, string>; status: Record<string, string> }; canSupervise: boolean; me: string;
};

const STATUS: Record<string, string> = { active: 'bg-ok-100 text-ok-700', cleaning: 'bg-watch-100 text-watch-700', maintenance: 'bg-treat-100 text-treat-700', closed: 'bg-crit-100 text-crit-700' };
const NEEDS_DISINFECTANT = ['disinfection', 'after_outbreak', 'after_loss'];

/** D-207: App 07 Housing: pen status, occupancy against capacity (BR-004) and cleaning records. */
export default function Housing(p: Props) {
    const [tab, setTab] = useTabParam('pens');
    const open = p.cleanings.filter((c) => !c.verifiedBy).length;
    return (
        <>
            <Head title="Pen Status & Cleaning" />
            <PageHeader title="Pen Status, Capacity & Cleaning" description="Only active pens take goats. A full pen refuses a move unless the owner or manager authorises it with a reason; care moves (maternity, isolation, welfare, kid care) are never stopped. A deep clean or disinfection empties and closes the pen until someone else verifies it." />
            <StatRow>
                <Stat label="Occupancy" value={p.kpi.occupancy === null ? '—' : `${p.kpi.occupancy}%`} note="Of pens with a capacity" />
                <Stat label="Full pens" value={p.kpi.full} />
                <Stat label="Over capacity" value={p.kpi.over} tone={p.kpi.over ? 'alert' : undefined} />
                <Stat label="Closed or cleaning" value={p.kpi.closed} />
                <Stat label="Cleanings to verify" value={p.kpi.toVerify} tone={p.kpi.toVerify ? 'alert' : undefined} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[
                { id: 'pens', label: 'Pens', count: p.pens.length || undefined },
                { id: 'cleaning', label: 'Cleaning', count: open || undefined },
                { id: 'overrides', label: 'Capacity Overrides', count: p.overrides.length || undefined },
            ]} />
            {tab === 'pens' ? <Pens {...p} /> : null}
            {tab === 'cleaning' ? <Cleanings {...p} /> : null}
            {tab === 'overrides' ? <Overrides {...p} /> : null}
        </>
    );
}

function Pens(p: Props) {
    const [shown, more] = useFive(p.pens, 30);
    return (
        <Panel flush title="Pens" description="Headcount comes from movements. Set a pen's capacity on Farms, Units & Pens.">
            {p.pens.length ? <ul className="divide-y divide-rule">{shown.map((pen) => <PenItem key={pen.id} pen={pen} {...p} />)}</ul> : <Empty title="No pens yet" />}
            {more}
        </Panel>
    );
}

function PenItem({ pen, options, canSupervise }: Props & { pen: PenRow }) {
    const [mode, setMode] = useState<'status' | 'clean' | null>(null);
    const st = useForm({ status: pen.status === 'cleaning' ? 'active' : pen.status, reason: pen.reason ?? '' });
    const cl = useForm({ kind: 'routine', disinfectant: '', notes: '' });
    const e = { ...st.errors, ...cl.errors } as Record<string, string>;
    const bar = pen.pct === null ? null : Math.min(pen.pct, 100);
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono">{pen.name}</strong>
                {pen.purpose ? <span className="text-ink-soft">{pen.purpose}</span> : null}
                <Chip className={STATUS[pen.status] ?? 'bg-glaze'}>{options.status[pen.status] ?? (pen.status === 'cleaning' ? 'Cleaning' : pen.status)}</Chip>
                <span className={pen.over ? 'font-semibold text-crit-700' : 'text-ink-soft'}>{pen.count}{pen.capacity ? ` / ${pen.capacity}` : ''} goats</span>
                {pen.lastClean ? <span className="text-ink-faint">Last cleaned {pen.lastClean}</span> : <span className="text-ink-faint">No cleaning recorded</span>}
                <span className="ml-auto flex gap-1">
                    {pen.status !== 'cleaning' ? <Button variant="secondary" onClick={() => setMode(mode === 'clean' ? null : 'clean')}>Record cleaning</Button> : null}
                    {canSupervise && pen.status !== 'cleaning' ? <Button variant="secondary" onClick={() => setMode(mode === 'status' ? null : 'status')}>Status</Button> : null}
                </span>
            </div>
            {bar !== null ? <div className="mt-1.5 h-1.5 w-full max-w-xs overflow-hidden rounded-full bg-glaze"><div className={pen.over ? 'h-full bg-crit-500' : pen.pct! >= 100 ? 'h-full bg-watch-400' : 'h-full bg-leaf-500'} style={{ width: `${bar}%` }} /></div> : null}
            {pen.reason ? <p className="mt-1 text-ink-soft">{pen.reason}{pen.statusBy ? ` · ${pen.statusBy}` : ''}</p> : null}
            {mode === 'status' ? (
                <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-[180px_1fr_auto] sm:items-end">
                    <Field label="Status" error={e.status}><select className="field" value={st.data.status} onChange={(x) => st.setData('status', x.target.value)}>{Object.entries(options.status).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="Reason" error={e.reason}><input className="field" value={st.data.reason} onChange={(x) => st.setData('reason', x.target.value)} placeholder={st.data.status === 'active' ? 'Not needed' : 'e.g. broken feeder, roof repair'} /></Field>
                    <Button disabled={st.processing} onClick={() => st.post(`/housing/pens/${pen.id}/status`, { preserveScroll: true, onSuccess: () => setMode(null) })}>Save</Button>
                </div>
            ) : null}
            {mode === 'clean' ? (
                <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-[220px_1fr_1fr_auto] sm:items-end">
                    <Field label="Cleaning" error={e.kind}><select className="field" value={cl.data.kind} onChange={(x) => cl.setData('kind', x.target.value)}>{Object.entries(options.kinds).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="Disinfectant" error={e.disinfectant}><input className="field" value={cl.data.disinfectant} onChange={(x) => cl.setData('disinfectant', x.target.value)} /></Field>
                    <Field label="Notes" error={e.notes}><input className="field" value={cl.data.notes} onChange={(x) => cl.setData('notes', x.target.value)} /></Field>
                    <Button disabled={cl.processing} onClick={() => cl.post(`/housing/pens/${pen.id}/clean`, { preserveScroll: true, onSuccess: () => { cl.reset(); setMode(null); } })}>{cl.data.kind === 'routine' ? 'Record' : 'Close pen & start'}</Button>
                    {cl.data.kind !== 'routine' ? <p className="text-xs text-ink-soft sm:col-span-4">The pen must be empty. It stays closed until the cleaning is finished and verified by someone else.</p> : null}
                </div>
            ) : null}
        </li>
    );
}

function Cleanings(p: Props) {
    const [shown, more] = useFive(p.cleanings, 20);
    return (
        <Panel flush title="Cleaning Records" description="Disinfection records the product and its contact time. A supervisor other than the cleaner verifies before the pen opens again.">
            {p.cleanings.length ? <ul className="divide-y divide-rule">{shown.map((c) => <CleaningItem key={c.id} c={c} {...p} />)}</ul> : <Empty title="No cleanings recorded">Record one from the Pens tab.</Empty>}
            {more}
        </Panel>
    );
}

function CleaningItem({ c, options, canSupervise, me }: Props & { c: Cleaning }) {
    const fin = useForm({ disinfectant: c.disinfectant ?? '', contact_minutes: '', notes: '' });
    const ver = useForm({});
    const e = fin.errors as Record<string, string>;
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono">{c.pen}</strong>
                <span className="font-semibold">{options.kinds[c.kind] ?? c.kind}</span>
                <span className="text-ink-soft">{c.started}{c.finished && c.finished !== c.started ? ` → ${c.finished}` : ''} · {c.by}</span>
                {c.disinfectant ? <span className="text-ink-soft">{c.disinfectant}{c.minutes ? `, ${c.minutes} min contact` : ''}</span> : null}
                {c.verifiedBy ? <Chip className="bg-ok-100 text-ok-700">Verified by {c.verifiedBy}</Chip> : c.finished ? <Chip className="bg-watch-100 text-watch-700">To verify</Chip> : <Chip className="bg-treat-100 text-treat-700">In progress</Chip>}
                {c.finished && !c.verifiedBy && canSupervise && c.by !== me ? <Button className="ml-auto" disabled={ver.processing} onClick={() => ver.post(`/housing/cleanings/${c.id}/verify`, { preserveScroll: true })}>Verify & open pen</Button> : null}
            </div>
            {c.notes ? <p className="text-ink-soft">{c.notes}</p> : null}
            {!c.finished ? (
                <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-[1fr_160px_1fr_auto] sm:items-end">
                    <Field label="Disinfectant" error={e.disinfectant}><input className="field" value={fin.data.disinfectant} onChange={(x) => fin.setData('disinfectant', x.target.value)} /></Field>
                    <Field label="Contact time (min)" error={e.contact_minutes}><input type="number" min={1} className="field" value={fin.data.contact_minutes} onChange={(x) => fin.setData('contact_minutes', x.target.value)} /></Field>
                    <Field label="Notes" error={e.notes}><input className="field" value={fin.data.notes} onChange={(x) => fin.setData('notes', x.target.value)} /></Field>
                    <Button disabled={fin.processing} onClick={() => { fin.transform((d) => ({ ...d, kind: c.kind, contact_minutes: d.contact_minutes || null })); fin.post(`/housing/cleanings/${c.id}/finish`, { preserveScroll: true }); }}>Finish</Button>
                    {NEEDS_DISINFECTANT.includes(c.kind) ? <p className="text-xs text-ink-soft sm:col-span-4">Record the disinfectant and its contact time.</p> : null}
                </div>
            ) : null}
        </li>
    );
}

function Overrides(p: Props) {
    const [shown, more] = useFive(p.overrides, 10);
    return (
        <Panel flush title="Moves Into Full Pens (90 days)" description="Each was authorised by the owner or manager with a reason (BR-004).">
            {p.overrides.length ? <ul className="divide-y divide-rule">{shown.map((o, i) => <li key={i} className="px-4 py-3 text-sm"><span className="font-mono font-semibold">{o.goat}</span> → <span className="font-mono">{o.pen}</span> <span className="text-ink-soft">· {o.on}</span><p className="text-ink-soft">{o.why}</p></li>)}</ul> : <Empty title="No over-capacity moves" />}
            {more}
        </Panel>
    );
}
